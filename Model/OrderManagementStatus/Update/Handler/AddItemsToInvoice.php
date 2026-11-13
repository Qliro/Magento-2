<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\OrderManagementStatus\Update\Handler;

use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Qliro\QliroOne\Api\Admin\OrderManagementStatusUpdateHandlerInterface;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\QliroOrder\Admin\CaptureRefundAllocator;
use Qliro\QliroOne\Model\QliroOrder\Admin\CaptureVatLedger;
use Qliro\QliroOne\Model\QliroOrder\Admin\SequentialRefundProcessor;

class AddItemsToInvoice implements OrderManagementStatusUpdateHandlerInterface
{
    /**
     * @var CaptureVatLedger
     */
    private CaptureVatLedger $captureVatLedger;

    /**
     * Payment constructor.
     * @param OrderPaymentRepositoryInterface $paymentRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param Manager $logManager
     * @param CaptureRefundAllocator $captureRefundAllocator
     * @param SequentialRefundProcessor $sequentialRefundProcessor
     * @param CaptureVatLedger|null $captureVatLedger
     */
    public function __construct(
        private readonly OrderPaymentRepositoryInterface $paymentRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Manager $logManager,
        private readonly CaptureRefundAllocator $captureRefundAllocator,
        private readonly SequentialRefundProcessor $sequentialRefundProcessor,
        ?CaptureVatLedger $captureVatLedger = null
    ) {
        $this->captureVatLedger = $captureVatLedger ?? new CaptureVatLedger();
    }

    /**
     * @inheritDoc
     */
    public function handleSuccess($qliroOrderManagementStatus, $omStatus)
    {
        $this->log($qliroOrderManagementStatus, $omStatus);

        $payment = $this->paymentRepository->get($omStatus->getRecordId());
        $order = $payment->getOrder();

        // Record the refunded amount only now that the PSP confirmed it, so a failed reversal
        // never marks a capture as refunded.
        $captureTxnId = $qliroOrderManagementStatus->getOriginalPaymentTransactionId();
        $amount = abs((float)$qliroOrderManagementStatus->getAmount());

        if ($captureTxnId) {
            $this->captureRefundAllocator->registerRefundForCapture($payment, (string)$captureTxnId, $amount);
        }

        $formattedPrice = $order->getBaseCurrency()->formatTxt(
            abs((float)$qliroOrderManagementStatus->getAmount())
        );

        $order->addCommentToStatusHistory(__('Refund of %1 confirmed successful', $formattedPrice));
        $this->orderRepository->save($order);

        // After the order, whose save writes the payment it loaded on its own
        if ($captureTxnId) {
            $this->recordVat($payment, function ($payment) use ($qliroOrderManagementStatus, $captureTxnId, $amount) {
                $this->captureVatLedger->confirm(
                    $payment,
                    (string)$qliroOrderManagementStatus->getPaymentTransactionId(),
                    (string)$captureTxnId,
                    $amount
                );
            });
        }

        // Send the next queued Addition (one in-flight return at a time).
        try {
            $this->sequentialRefundProcessor->continueQueue($payment);
        } catch (\Exception $exception) {
            $this->logManager->critical($exception, ['extra' => ['record_id' => $omStatus->getRecordId()]]);

            $order->addCommentToStatusHistory(__(
                'A further part of this refund could not be sent to Qliro. Manual review required.'
            ));
            $this->orderRepository->save($order);
        }
    }

    /**
     * @inheritDoc
     */
    public function handleCancelled($qliroOrderManagementStatus, $omStatus)
    {
        $this->log($qliroOrderManagementStatus, $omStatus);
    }

    /**
     * @inheritDoc
     */
    public function handleError($qliroOrderManagementStatus, $omStatus)
    {
        $this->log($qliroOrderManagementStatus, $omStatus);

        try {
            $payment = $this->paymentRepository->get($omStatus->getRecordId());
            $order = $payment->getOrder();

            // PSP rejected this return: stop the sequence, drop queued Additions and flag for
            // manual review so the partial refund is not left silently inconsistent.
            $remaining = $this->sequentialRefundProcessor->abort($payment);

            $failedAmount = abs((float)$qliroOrderManagementStatus->getAmount());
            $remainingTotal = 0.0;

            foreach ($remaining as $entry) {
                $remainingTotal += (float)($entry['amount'] ?? 0);
            }

            $description = $qliroOrderManagementStatus->getProviderResultDescription();

            $order->addCommentToStatusHistory(__(
                'Refund of %1 FAILED at Qliro (transaction %2). %3 The sequential refund has been '
                . 'stopped; %4 of further refunds was not sent. Manual review required.',
                $order->getBaseCurrency()->formatTxt($failedAmount),
                $qliroOrderManagementStatus->getPaymentTransactionId(),
                $description ? '(' . $description . ')' : '',
                $order->getBaseCurrency()->formatTxt($remainingTotal)
            ));

            $this->orderRepository->save($order);

            $this->recordVat($payment, function ($payment) use ($qliroOrderManagementStatus) {
                $this->captureVatLedger->drop(
                    $payment,
                    (string)$qliroOrderManagementStatus->getPaymentTransactionId()
                );
            });
        } catch (\Exception $exception) {
            $this->logManager->critical($exception, ['extra' => ['record_id' => $omStatus->getRecordId()]]);
        }
    }

    /**
     * @inheritDoc
     */
    public function handleInProcess($qliroOrderManagementStatus, $omStatus)
    {
        $this->log($qliroOrderManagementStatus, $omStatus);
    }

    /**
     * @inheritDoc
     */
    public function handleOnHold($qliroOrderManagementStatus, $omStatus)
    {
        $this->log($qliroOrderManagementStatus, $omStatus);
    }

    /**
     * @inheritDoc
     */
    public function handleUserInteraction($qliroOrderManagementStatus, $omStatus)
    {
        $this->log($qliroOrderManagementStatus, $omStatus);
    }

    /**
     * @inheritDoc
     */
    public function handleCreated($qliroOrderManagementStatus, $omStatus)
    {
        $this->log($qliroOrderManagementStatus, $omStatus);
    }

    /**
     * Update the VAT ledger on the payment and save it. The ledger only steers the VAT of later refunds,
     * so a failure here is logged and never fails the callback.
     *
     * @param \Magento\Sales\Api\Data\OrderPaymentInterface $payment
     * @param callable $update
     * @return void
     */
    private function recordVat($payment, callable $update): void
    {
        if (!$payment instanceof \Magento\Sales\Model\Order\Payment) {
            return;
        }

        try {
            $update($payment);
            $this->paymentRepository->save($payment);
        } catch (\Exception $exception) {
            $this->logManager->debug(
                'The VAT ledger of a refund could not be saved: ' . $exception->getMessage(),
                ['extra' => ['payment_id' => $payment->getId()]]
            );
        }
    }

    /**
     * @inheritDoc
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
