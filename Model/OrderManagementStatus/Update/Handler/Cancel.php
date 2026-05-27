<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\OrderManagementStatus\Update\Handler;

use Qliro\QliroOne\Api\Admin\OrderManagementStatusUpdateHandlerInterface;
use Qliro\QliroOne\Model\OrderManagementStatus;

class Cancel implements OrderManagementStatusUpdateHandlerInterface
{
    /**
     * Class constructor
     *
     * @param \Qliro\QliroOne\Model\Logger\Manager $logManager
     */
    public function __construct(
        private readonly \Qliro\QliroOne\Model\Logger\Manager $logManager
    ) {
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     */
    public function handleSuccess(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        $this->log($qliroOrderManagementStatus, $omStatus);
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     */
    public function handleCancelled(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        $this->log($qliroOrderManagementStatus, $omStatus);
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     */
    public function handleError(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        $this->log($qliroOrderManagementStatus, $omStatus);
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     */
    public function handleInProcess(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        $this->log($qliroOrderManagementStatus, $omStatus);
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     */
    public function handleOnHold(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        $this->log($qliroOrderManagementStatus, $omStatus);
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     */
    public function handleUserInteraction(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        $this->log($qliroOrderManagementStatus, $omStatus);
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     */
    public function handleCreated(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        $this->log($qliroOrderManagementStatus, $omStatus);
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     */
    private function log(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        $merchantReference = $qliroOrderManagementStatus['MerchantReference'] ?? null;
        $this->logManager->setMerchantReference($merchantReference);

        $logData = [
            'status' => $qliroOrderManagementStatus['Status'] ?? null,
            'qliro_order_id' => $qliroOrderManagementStatus['OrderId'] ?? null,
            'transaction_id' => $omStatus->getTransactionId(),
            'transaction_status' => $omStatus->getTransactionStatus(),
            'record_type' => $omStatus->getRecordType(),
            'record_id' => $omStatus->getRecordId(),
        ];

        $this->logManager->info('Order cancellation transaction changed status', ['extra' => $logData]);
    }
}
