<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\NoSuchEntityException;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusInterface;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusResponseInterface;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusResponseInterfaceFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Notification\QliroOrderManagementStatusResponse;
use Qliro\QliroOne\Model\OrderManagementStatus\Update\HandlerPool as  OrderManagementHandlerPool;
use Qliro\QliroOne\Model\ResourceModel\Lock;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterfaceFactory;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterface;

/**
 * QliroOne management class
 */
class TransactionStatus extends AbstractManagement
{
    private const LOCK_ATTEMPTS = 10;

    private const MONEY_TAKING_TYPES = ['Capture', 'Debit'];

    private const LOCK_WAIT_MICROSECONDS = 300000;

    /**
     * @var \Qliro\QliroOne\Api\LinkRepositoryInterface
     */
    private $linkRepository;

    /**
     * @var \Qliro\QliroOne\Model\Logger\Manager
     */
    private $logManager;

    /**
     * @var \Qliro\QliroOne\Model\ResourceModel\Lock
     */
    private $lock;

    /**
     * @var QliroOrderManagementStatusResponseInterfaceFactory
     */
    private $qliroOrderManagementStatusResponseFactory;

    /**
     * @var \Qliro\QliroOne\Api\Data\OrderManagementStatusInterfaceFactory
     */
    private $orderManagementStatusInterfaceFactory;

    /**
     * @var OrderManagementStatusRepositoryInterface
     */
    private $orderManagementStatusRepository;

    /**
     * @var OrderManagementStatus\Update\HandlerPool
     */
    private $statusUpdateHandlerPool;

    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    private $eventManager;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * Inject dependencies
     *
     * @param LinkRepositoryInterface $linkRepository
     * @param LogManager $logManager
     * @param Lock $lock
     * @param QliroOrderManagementStatusResponseInterfaceFactory $qliroOrderManagementStatusResponseFactory
     * @param OrderManagementStatusInterfaceFactory $orderManagementStatusInterfaceFactory
     * @param OrderManagementStatusRepositoryInterface $orderManagementStatusRepository
     * @param OrderManagementHandlerPool $statusUpdateHandlerPool
     * @param SearchCriteriaBuilder|null $searchCriteriaBuilder
     */
    public function __construct(
        LinkRepositoryInterface $linkRepository,
        LogManager $logManager,
        Lock $lock,
        QliroOrderManagementStatusResponseInterfaceFactory $qliroOrderManagementStatusResponseFactory,
        OrderManagementStatusInterfaceFactory $orderManagementStatusInterfaceFactory,
        OrderManagementStatusRepositoryInterface $orderManagementStatusRepository,
        OrderManagementHandlerPool $statusUpdateHandlerPool,
        ?SearchCriteriaBuilder $searchCriteriaBuilder = null
    ) {
        $this->linkRepository = $linkRepository;
        $this->logManager = $logManager;
        $this->lock = $lock;
        $this->qliroOrderManagementStatusResponseFactory = $qliroOrderManagementStatusResponseFactory;
        $this->orderManagementStatusInterfaceFactory = $orderManagementStatusInterfaceFactory;
        $this->orderManagementStatusRepository = $orderManagementStatusRepository;
        $this->statusUpdateHandlerPool = $statusUpdateHandlerPool;
        // Optional for a subclass on the old signature, Magento passes null for it instead of resolving it
        $this->searchCriteriaBuilder = $searchCriteriaBuilder
            ?: ObjectManager::getInstance()->get(SearchCriteriaBuilder::class);
    }

    /**
     * Handles Order Management Status Transaction notifications
     *
     * @param \Qliro\QliroOne\Model\Notification\QliroOrderManagementStatus $qliroOrderManagementStatus
     * @return \Qliro\QliroOne\Model\Notification\QliroOrderManagementStatusResponse
     */
    public function handle($qliroOrderManagementStatus)
    {
        $qliroOrderId = $qliroOrderManagementStatus->getOrderId();

        try {
            $link = $this->findLink($qliroOrderId);
            $this->logManager->setMerchantReference($link->getReference());

            if (empty($link->getOrderId())) {
                // Money taken on a live checkout is for an order that may still be placed
                if ($link->getIsActive() && $this->isMoneyTaken($qliroOrderManagementStatus)) {
                    $this->logNothingUpdated(
                        $qliroOrderManagementStatus,
                        QliroOrderManagementStatusResponseInterface::RESPONSE_NOT_PLACED
                    );

                    return $this->qliroOrderManagementStatusRespond(
                        QliroOrderManagementStatusResponseInterface::RESPONSE_NOT_PLACED
                    );
                }

                return $this->nothingToUpdate(
                    $qliroOrderManagementStatus,
                    QliroOrderManagementStatusResponseInterface::MESSAGE_NO_ORDER
                );
            }

            if (!$this->updateTransactionStatus($qliroOrderManagementStatus)) {
                return $this->qliroOrderManagementStatusRespond(
                    QliroOrderManagementStatusResponseInterface::RESPONSE_FAILED
                );
            }
        } catch (NoSuchEntityException $exception) {
            return $this->nothingToUpdate(
                $qliroOrderManagementStatus,
                QliroOrderManagementStatusResponseInterface::MESSAGE_NO_LINK
            );
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'qliro_order_id' => $qliroOrderId,
                    ],
                ]
            );

            return $this->qliroOrderManagementStatusRespond(
                QliroOrderManagementStatusResponseInterface::RESPONSE_FAILED
            );
        }

        return $this->qliroOrderManagementStatusRespond(
            QliroOrderManagementStatusResponseInterface::RESPONSE_RECEIVED
        );
    }

    /**
     * Qliro resends until the answer is received, and a push with no order to update never will be
     *
     * @param \Qliro\QliroOne\Model\Notification\QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param string $message
     * @return \Qliro\QliroOne\Api\Data\QliroOrderManagementStatusResponseInterface
     */
    private function nothingToUpdate($qliroOrderManagementStatus, string $message)
    {
        $this->logNothingUpdated($qliroOrderManagementStatus, $message);

        $response = $this->qliroOrderManagementStatusRespond(
            QliroOrderManagementStatusResponseInterface::RESPONSE_RECEIVED
        );
        if ($response instanceof QliroOrderManagementStatusResponse) {
            $response->setMessage($message);
        }

        return $response;
    }

    /**
     * Money taken without a Magento order is a warning, the rest of an abandoned checkout is routine
     *
     * @param \Qliro\QliroOne\Model\Notification\QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param string $message
     * @return void
     */
    private function logNothingUpdated($qliroOrderManagementStatus, string $message): void
    {
        $context = [
            'status' => $qliroOrderManagementStatus->getStatus(),
            'payment_type' => $qliroOrderManagementStatus->getPaymentType(),
            'transaction_id' => $qliroOrderManagementStatus->getPaymentTransactionId(),
            'qliro_order_id' => $qliroOrderManagementStatus->getOrderId(),
            'message' => $message,
        ];
        $this->logManager->log(
            $this->isMoneyTaken($qliroOrderManagementStatus) ? 'warning' : 'info',
            'Transaction status {status} ({payment_type}) of transaction {transaction_id} for Qliro order '
            . '{qliro_order_id}: {message}',
            $context + ['extra' => [
                'qliro_order_id' => $context['qliro_order_id'],
                'merchant_reference' => $qliroOrderManagementStatus->getMerchantReference(),
            ]]
        );
    }

    /**
     * A successful capture or debit took money that no Magento order accounts for
     *
     * @param \Qliro\QliroOne\Model\Notification\QliroOrderManagementStatus $qliroOrderManagementStatus
     * @return bool
     */
    private function isMoneyTaken($qliroOrderManagementStatus): bool
    {
        return $qliroOrderManagementStatus->getStatus() === QliroOrderManagementStatusInterface::STATUS_SUCCESS
            && in_array($qliroOrderManagementStatus->getPaymentType(), self::MONEY_TAKING_TYPES, true);
    }

    /**
     * The active link first, then a deactivated one with an order: a cancelled or refused order keeps it there
     *
     * @param int|string $qliroOrderId
     * @return \Qliro\QliroOne\Api\Data\LinkInterface
     * @throws NoSuchEntityException
     */
    private function findLink($qliroOrderId)
    {
        try {
            return $this->linkRepository->getByQliroOrderId($qliroOrderId);
        } catch (NoSuchEntityException $exception) {
            // The repository refuses a value MySQL would cast to another id
            if (!ctype_digit((string)$qliroOrderId) || (int)$qliroOrderId <= 0) {
                throw $exception;
            }

            $links = $this->linkRepository->getList(
                $this->searchCriteriaBuilder->addFilter('qliro_order_id', $qliroOrderId)->create()
            )->getItems();

            foreach ($links as $link) {
                if (!empty($link->getOrderId())) {
                    return $link;
                }
            }

            if ($links) {
                return reset($links);
            }

            throw $exception;
        }
    }

    /**
     * If a transaction is received that is of same type as previou, same transaction id and marked as handled, it does
     * not have to be handled, since it was done already the first time it arrived.
     * Reply true when properly handled
     *
     * @param \Qliro\QliroOne\Model\Notification\QliroOrderManagementStatus $qliroOrderManagementStatus
     * @return bool
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     */
    private function updateTransactionStatus($qliroOrderManagementStatus)
    {
        $result = true;

        try {
            $qliroOrderId = $qliroOrderManagementStatus->getOrderId();

            /** @var \Qliro\QliroOne\Model\OrderManagementStatus $omStatus */
            $omStatus = $this->orderManagementStatusInterfaceFactory->create();
            $omStatus->setTransactionId($qliroOrderManagementStatus->getPaymentTransactionId());
            $omStatus->setTransactionStatus($qliroOrderManagementStatus->getStatus());
            $omStatus->setQliroOrderId($qliroOrderId);
            $omStatus->setMessage('Notification update');

            $handleTransaction = true;

            try {
                /** @var \Qliro\QliroOne\Model\OrderManagementStatus $omStatusParent */
                $omStatusParent = $this->orderManagementStatusRepository->getParent(
                    $qliroOrderManagementStatus->getPaymentTransactionId()
                );

                if ($omStatusParent) {
                    $omStatus->setRecordId($omStatusParent->getRecordId());
                    $omStatus->setRecordType($omStatusParent->getRecordType());
                } else {
                    // A shipment capture whose shipment is still being saved, the cron reconciles it
                    $this->logManager->info(
                        'No record for transaction {transaction_id}, if it is a shipment capture still being saved the capture reconciliation applies it',
                        ['transaction_id' => $omStatus->getTransactionId()]
                    );
                }

                $handleTransaction = !$this->isHandled($omStatus->getTransactionId(), $omStatus->getTransactionStatus());
            } catch (\Exception $exception) {
                $this->logManager->debug(
                    $exception,
                    [
                        'extra' => [
                            'qliro_order_id' => $qliroOrderId,
                            'transaction_id' => $omStatus->getTransactionId(),
                            'transaction_status' => $omStatus->getTransactionStatus(),
                            'record_type' => $omStatus->getRecordType(),
                            'record_id' => $omStatus->getRecordId(),
                        ],
                    ]
                );
                $result = false;
            }

            if ($handleTransaction) {
                if ($this->acquireLock($qliroOrderId)) {
                    // The shipment may have been committed while this waited for the lock
                    if (!$omStatus->getRecordType()) {
                        $parent = $this->orderManagementStatusRepository->getParent($omStatus->getTransactionId());

                        if ($parent) {
                            $omStatus->setRecordId($parent->getRecordId());
                            $omStatus->setRecordType($parent->getRecordType());
                        }
                    }

                    // The reconciliation and Qliro's own callback can both get this far
                    if ($this->isHandled($omStatus->getTransactionId(), $omStatus->getTransactionStatus())) {
                        $omStatus->setNotificationStatus(OrderManagementStatusInterface::NOTIFICATION_STATUS_SKIPPED);
                    } else {
                        $omStatus->setNotificationStatus(OrderManagementStatusInterface::NOTIFICATION_STATUS_NEW);
                        $this->orderManagementStatusRepository->save($omStatus);
                        if ($this->statusUpdateHandlerPool->handle($qliroOrderManagementStatus, $omStatus)) {
                            $omStatus->setNotificationStatus(OrderManagementStatusInterface::NOTIFICATION_STATUS_DONE);
                        }
                    }
                    // Saved before the unlock, so the next one in sees it handled
                    $this->orderManagementStatusRepository->save($omStatus);
                    $this->lock->unlock($qliroOrderId);

                    return $result;
                } else {
                    $omStatus->setMessage('Skipped due to lock');
                    $omStatus->setNotificationStatus(OrderManagementStatusInterface::NOTIFICATION_STATUS_SKIPPED);
                }
            } else {
                $omStatus->setNotificationStatus(OrderManagementStatusInterface::NOTIFICATION_STATUS_SKIPPED);
            }

            $this->orderManagementStatusRepository->save($omStatus);
        } catch (\Exception $exception) {
            $logData = [
                'qliro_order_id' => $qliroOrderId ?? null,
            ];

            if (isset($omStatus)) {
                $logData = array_merge($logData, [
                    'transaction_id' => $omStatus->getTransactionId(),
                    'transaction_status' => $omStatus->getTransactionStatus(),
                    'record_type' => $omStatus->getRecordType(),
                    'record_id' => $omStatus->getRecordId(),
                ]);
            }

            $this->logManager->critical(
                $exception,
                [
                    'extra' => $logData,
                ]
            );

            if (isset($omStatus) && $omStatus && $omStatus->getId()) {
                $omStatus->setNotificationStatus(OrderManagementStatusInterface::NOTIFICATION_STATUS_ERROR);
                $this->orderManagementStatusRepository->save($omStatus);
            }
            $this->lock->unlock($qliroOrderId);

            $result = false;
        }

        return $result;
    }

    /**
     * The lock is held for one handling, so a short wait beats dropping the status
     *
     * @param int $qliroOrderId
     * @return bool
     */
    private function acquireLock($qliroOrderId)
    {
        for ($attempt = 1; $attempt < self::LOCK_ATTEMPTS; $attempt++) {
            if ($this->lock->lock($qliroOrderId)) {
                return true;
            }

            usleep(self::LOCK_WAIT_MICROSECONDS);
        }

        return $this->lock->lock($qliroOrderId);
    }

    /**
     * Whether this status is the last one handled for the transaction
     *
     * @param int|string $transactionId
     * @param string $status
     * @return bool
     */
    public function isHandled($transactionId, $status): bool
    {
        $previous = $this->orderManagementStatusRepository->getPrevious($transactionId);

        return $previous && $previous->getTransactionStatus() == $status;
    }

    /**
     * @param string $result
     * @return mixed
     */
    private function qliroOrderManagementStatusRespond($result)
    {
        return $this->qliroOrderManagementStatusResponseFactory->create()->setCallbackResponse($result);
    }
}
