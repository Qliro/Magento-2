<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\Exception\NoSuchEntityException;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusResponseInterface;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusResponseInterfaceFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
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
     * Inject dependencies
     *
     * @param LinkRepositoryInterface $linkRepository
     * @param LogManager $logManager
     * @param Lock $lock
     * @param QliroOrderManagementStatusResponseInterfaceFactory $qliroOrderManagementStatusResponseFactory
     * @param OrderManagementStatusInterfaceFactory $orderManagementStatusInterfaceFactory
     * @param OrderManagementStatusRepositoryInterface $orderManagementStatusRepository
     * @param OrderManagementHandlerPool $statusUpdateHandlerPool
     */
    public function __construct(
        LinkRepositoryInterface $linkRepository,
        LogManager $logManager,
        Lock $lock,
        QliroOrderManagementStatusResponseInterfaceFactory $qliroOrderManagementStatusResponseFactory,
        OrderManagementStatusInterfaceFactory $orderManagementStatusInterfaceFactory,
        OrderManagementStatusRepositoryInterface $orderManagementStatusRepository,
        OrderManagementHandlerPool $statusUpdateHandlerPool
    ) {
        $this->linkRepository = $linkRepository;
        $this->logManager = $logManager;
        $this->lock = $lock;
        $this->qliroOrderManagementStatusResponseFactory = $qliroOrderManagementStatusResponseFactory;
        $this->orderManagementStatusInterfaceFactory = $orderManagementStatusInterfaceFactory;
        $this->orderManagementStatusRepository = $orderManagementStatusRepository;
        $this->statusUpdateHandlerPool = $statusUpdateHandlerPool;
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
            $link = $this->linkRepository->getByQliroOrderId($qliroOrderId);
            $this->logManager->setMerchantReference($link->getReference());

            $orderId = $link->getOrderId();

            if (empty($orderId)) {
                /* Should not happen, but if it does, respond with this to stop new notifications */
                return $this->qliroOrderManagementStatusRespond(
                    QliroOrderManagementStatusResponseInterface::RESPONSE_ORDER_NOT_FOUND
                );
            } elseif (!$this->updateTransactionStatus($qliroOrderManagementStatus)) {
                return $this->qliroOrderManagementStatusRespond(
                    QliroOrderManagementStatusResponseInterface::RESPONSE_ORDER_NOT_FOUND
                );
            }
        } catch (NoSuchEntityException $exception) {
            /* No more qliro notifications should be sent */
            return $this->qliroOrderManagementStatusRespond(
                QliroOrderManagementStatusResponseInterface::RESPONSE_ORDER_NOT_FOUND
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
                QliroOrderManagementStatusResponseInterface::RESPONSE_ORDER_NOT_FOUND
            );
        }

        return $this->qliroOrderManagementStatusRespond(
            QliroOrderManagementStatusResponseInterface::RESPONSE_RECEIVED
        );
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
                }

                /** @var \Qliro\QliroOne\Model\OrderManagementStatus $omStatusPrevious */
                $omStatusPrevious = $this->orderManagementStatusRepository->getPrevious(
                    $qliroOrderManagementStatus->getPaymentTransactionId()
                );

                if ($omStatusPrevious) {
                    if ($omStatus->getTransactionStatus() == $omStatusPrevious->getTransactionStatus()) {
                        $handleTransaction = false;
                    }
                }
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
                if ($this->lock->lock($qliroOrderId)) {
                    $omStatus->setNotificationStatus(OrderManagementStatusInterface::NOTIFICATION_STATUS_NEW);
                    $this->orderManagementStatusRepository->save($omStatus);
                    if ($this->statusUpdateHandlerPool->handle($qliroOrderManagementStatus, $omStatus)) {
                        $omStatus->setNotificationStatus(OrderManagementStatusInterface::NOTIFICATION_STATUS_DONE);
                    }
                    $this->lock->unlock($qliroOrderId);
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
     * @param string $result
     * @return mixed
     */
    private function qliroOrderManagementStatusRespond($result)
    {
        return $this->qliroOrderManagementStatusResponseFactory->create()->setCallbackResponse($result);
    }
}
