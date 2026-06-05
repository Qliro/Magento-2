<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\OrderManagementStatus\Update\Handler;

use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Qliro\QliroOne\Api\Admin\OrderManagementStatusUpdateHandlerInterface;
use Qliro\QliroOne\Model\Logger\Manager;

class AddItemsToInvoice implements OrderManagementStatusUpdateHandlerInterface
{
    /**
     * Inject dependencies
     *
     * @param Manager $logManager
     */
    /**
     * Payment constructor.
     * @param OrderPaymentRepositoryInterface $paymentRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param Manager $logManager
     */
    public function __construct(
        private readonly OrderPaymentRepositoryInterface $paymentRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Manager $logManager
    ) {
    }

    /**
     * @inerhitDoc
     */
    public function handleSuccess($qliroOrderManagementStatus, $omStatus)
    {
        $this->log($qliroOrderManagementStatus, $omStatus);

        $payment = $this->paymentRepository->get($omStatus->getRecordId());
        $order = $payment->getOrder();

        $formattedPrice = $order->getBaseCurrency()->formatTxt(
            abs($qliroOrderManagementStatus->getAmount())
        );

        $order->addCommentToStatusHistory(__('Refund of %1 confirmed successful', $formattedPrice));
        $this->orderRepository->save($order);

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

        $this->logManager->info('Add items to invoice transaction changed status', ['extra' => $logData]);
    }
}
