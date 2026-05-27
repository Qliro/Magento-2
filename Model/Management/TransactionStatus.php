<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\Exception\NoSuchEntityException;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\OrderManagementStatus\Update\HandlerPool as  OrderManagementHandlerPool;
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
     * @param OrderManagementStatusInterfaceFactory $orderManagementStatusInterfaceFactory
     * @param OrderManagementStatusRepositoryInterface $orderManagementStatusRepository
     * @param OrderManagementHandlerPool $statusUpdateHandlerPool
     */
    public function __construct(
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly LogManager $logManager,
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
                // Link exists but has no Magento order yet — tell Qliro to stop retrying.
                $this->logManager->warning(
                    'TransactionStatus: link found but no Magento order_id — stopping retries.',
                    ['extra' => ['qliro_order_id' => $qliroOrderId]]
                );
                return $this->qliroOrderManagementStatusRespond('OrderNotFound');
            }

            if (!$this->updateTransactionStatus($qliroOrderManagementStatus)) {
                // The handler failed to process the transaction but the order IS in Magento.
                // Return 'Received' (200) to stop Qliro from retrying indefinitely.
                // The omStatus record was already persisted, so the transaction can be
                // reprocessed manually if the Magento order state needs to be corrected.
                $this->logManager->critical(
                    'TransactionStatus: handler failed to process transaction for existing order.',
                    ['extra' => [
                        'qliro_order_id'     => $qliroOrderId,
                        'order_id'           => $orderId,
                        'payment_type'       => $qliroOrderManagementStatus['PaymentType'] ?? null,
                        'transaction_id'     => $qliroOrderManagementStatus['PaymentTransactionId'] ?? null,
                    ]]
                );
                return $this->qliroOrderManagementStatusRespond('Received');
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
    private function updateTransactionStatus(array $qliroOrderManagementStatus): bool
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
