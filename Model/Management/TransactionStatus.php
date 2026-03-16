<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\Exception\NoSuchEntityException;
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
class TransactionStatus
{
    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    private $eventManager;

    /**
     * Class constructor
     *
     * @param LinkRepositoryInterface $linkRepository
     * @param LogManager $logManager
     * @param Lock $lock
     * @param OrderManagementStatusInterfaceFactory $orderManagementStatusInterfaceFactory
     * @param OrderManagementStatusRepositoryInterface $orderManagementStatusRepository
     * @param OrderManagementHandlerPool $statusUpdateHandlerPool
     */
    public function __construct(
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly LogManager $logManager,
        private readonly Lock $lock,
        private readonly OrderManagementStatusInterfaceFactory $orderManagementStatusInterfaceFactory,
        private readonly OrderManagementStatusRepositoryInterface $orderManagementStatusRepository,
        private readonly OrderManagementHandlerPool $statusUpdateHandlerPool
    ) {
    }

    /**
     * Handles Order Management Status Transaction notifications
     *
     * @param array $qliroOrderManagementStatus
     * @return array
     */
    public function handle(array $qliroOrderManagementStatus): array
    {
        $qliroOrderId = $qliroOrderManagementStatus['OrderId'] ?? null;

        try {
            $link = $this->linkRepository->getByQliroOrderId($qliroOrderId);
            $this->logManager->setMerchantReference($link->getReference());

            $orderId = $link->getOrderId();

            if (empty($orderId)) {
                /* Should not happen, but if it does, respond with this to stop new notifications */
                return $this->qliroOrderManagementStatusRespond(
                    'OrderNotFound'
                );
            } elseif (!$this->updateTransactionStatus($qliroOrderManagementStatus)) {
                return $this->qliroOrderManagementStatusRespond(
                    'OrderNotFound'
                );
            }
        } catch (NoSuchEntityException $exception) {
            /* No more qliro notifications should be sent */
            return $this->qliroOrderManagementStatusRespond(
                'OrderNotFound'
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
                'OrderNotFound'
            );
        }

        return $this->qliroOrderManagementStatusRespond(
            'Received'
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
            $qliroOrderId = $qliroOrderManagementStatus['OrderId'] ?? null;

            /** @var \Qliro\QliroOne\Model\OrderManagementStatus $omStatus */
            $omStatus = $this->orderManagementStatusInterfaceFactory->create();
            $omStatus->setTransactionId($qliroOrderManagementStatus['PaymentTransactionId'] ?? null);
            $omStatus->setTransactionStatus($qliroOrderManagementStatus['Status'] ?? null);
            $omStatus->setQliroOrderId($qliroOrderId);
            $omStatus->setMessage('Notification update');

            $handleTransaction = true;

            try {
                /** @var \Qliro\QliroOne\Model\OrderManagementStatus $omStatusParent */
                $omStatusParent = $this->orderManagementStatusRepository->getParent(
                    $qliroOrderManagementStatus['PaymentTransactionId'] ?? null
                );

                if ($omStatusParent) {
                    $omStatus->setRecordId($omStatusParent->getRecordId());
                    $omStatus->setRecordType($omStatusParent->getRecordType());
                }

                /** @var \Qliro\QliroOne\Model\OrderManagementStatus $omStatusPrevious */
                $omStatusPrevious = $this->orderManagementStatusRepository->getPrevious(
                    $qliroOrderManagementStatus['PaymentTransactionId'] ?? null
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
                $omStatus->setNotificationStatus(OrderManagementStatusInterface::NOTIFICATION_STATUS_NEW);
                $this->orderManagementStatusRepository->save($omStatus);
                if ($this->statusUpdateHandlerPool->handle($qliroOrderManagementStatus, $omStatus)) {
                    $omStatus->setNotificationStatus(OrderManagementStatusInterface::NOTIFICATION_STATUS_DONE);
                }
                $this->lock->unlock($qliroOrderId);
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
    private function qliroOrderManagementStatusRespond(string $result): array
    {
        return ['CallbackResponse' => $result];
    }
}
