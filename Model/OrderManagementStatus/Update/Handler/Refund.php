<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\OrderManagementStatus\Update\Handler;

use Qliro\QliroOne\Api\Admin\OrderManagementStatusUpdateHandlerInterface;

class Refund implements OrderManagementStatusUpdateHandlerInterface
{
    /**
     * @var \Qliro\QliroOne\Model\Logger\Manager
     */
    private $logManager;

    /**
     * Inject dependencies
     *
     * @param \Qliro\QliroOne\Model\Logger\Manager $logManager
     */
    public function __construct(
        \Qliro\QliroOne\Model\Logger\Manager $logManager
    ) {
        $this->logManager = $logManager;
    }

    /**
     * @inerhitDoc
     */
    public function handleSuccess($qliroOrderManagementStatus, $omStatus)
    {
        $this->log($qliroOrderManagementStatus, $omStatus);
    }

    /**
     * @inerhitDoc
     */
    public function handleCancelled($qliroOrderManagementStatus, $omStatus)
    {
        $this->log($qliroOrderManagementStatus, $omStatus);
    }

    /**
     * @inerhitDoc
     */
    public function handleError($qliroOrderManagementStatus, $omStatus)
    {
        $this->log($qliroOrderManagementStatus, $omStatus);
    }

    /**
     * @inerhitDoc
     */
    public function handleInProcess($qliroOrderManagementStatus, $omStatus)
    {
        $this->log($qliroOrderManagementStatus, $omStatus);
    }

    /**
     * @inerhitDoc
     */
    public function handleOnHold($qliroOrderManagementStatus, $omStatus)
    {
        $this->log($qliroOrderManagementStatus, $omStatus);
    }

    /**
     * @inerhitDoc
     */
    public function handleUserInteraction($qliroOrderManagementStatus, $omStatus)
    {
        $this->log($qliroOrderManagementStatus, $omStatus);
    }

    /**
     * @inerhitDoc
     */
    public function handleCreated($qliroOrderManagementStatus, $omStatus)
    {
        $this->log($qliroOrderManagementStatus, $omStatus);
    }

    /**
     * @inerhitDoc
     */
    private function log($qliroOrderManagementStatus, $omStatus)
    {
        $merchantReference = $qliroOrderManagementStatus->getMerchantReference();
        $this->logManager->setMerchantReference($merchantReference);

        $logData = [
            'status' => $qliroOrderManagementStatus->getStatus(),
            'qliro_order_id' => $qliroOrderManagementStatus->getOrderId(),
            'transaction_id' => $omStatus->getTransactionId(),
            'transaction_status' => $omStatus->getTransactionStatus(),
            'record_type' => $omStatus->getRecordType(),
            'record_id' => $omStatus->getRecordId(),
        ];

        $this->logManager->info('Order refund transaction changed status', ['extra' => $logData]);
    }
}
