<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\OrderManagementStatus\Update\Handler;

use Qliro\QliroOne\Api\Admin\OrderManagementStatusUpdateHandlerInterface;
use Magento\Sales\Model\Order;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\OrderManagementStatus;

class Payment implements OrderManagementStatusUpdateHandlerInterface
{
    /**
     * Class constructor
     *
     * @param \Magento\Sales\Api\OrderPaymentRepositoryInterface $paymentRepository
     * @param \Magento\Sales\Api\TransactionRepositoryInterface $paymentTransactionRepository
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Qliro\QliroOne\Model\Logger\Manager $logManager
     */
    public function __construct(
        private readonly \Magento\Sales\Api\OrderPaymentRepositoryInterface $paymentRepository,
        private readonly \Magento\Sales\Api\TransactionRepositoryInterface $paymentTransactionRepository,
        private readonly \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        private readonly \Qliro\QliroOne\Model\Logger\Manager $logManager
    ) {
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     */
    public function handleSuccess(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        $payment = $this->getPayment($omStatus);
        $order = $payment->getOrder();

        /*
         * Update Order
         */
        try {
            if ($order->getState() == Order::STATE_HOLDED) {
                $order->unhold();
            }

            $formattedPrice = $order->getBaseCurrency()->formatTxt(
                $qliroOrderManagementStatus['Amount'] ?? null
            );

            $order->addStatusHistoryComment(__('Capture of %1 confirmed successful', $formattedPrice));

            $this->orderRepository->save($order);
        } catch (\Exception $exception) {
            $this->logManager->debug(
                $exception,
                [
                    'extra' => [
                        'qliro_order_id' => $qliroOrderManagementStatus['OrderId'] ?? null,
                        'payment_id' => $payment->getId(),
                    ],
                ]
            );
            throw new TerminalException('Could not handle Invoice Success', $exception->getCode(), $exception);
        }

        /*
         * Update Payment Transaction
         */
        try {
            /** @var \Magento\Sales\Model\Order\Payment\Transaction $paymentTransaction */
            $paymentTransaction = $this->getPaymentTransaction(
                $qliroOrderManagementStatus['PaymentTransactionId'] ?? null,
                $payment->getId(),
                $order->getId()
            );

            $paymentTransaction->setAdditionalInformation(
                'provider_result_description',
                $qliroOrderManagementStatus['ProviderResultDescription'] ?? null
            );
            $paymentTransaction->setAdditionalInformation(
                'provider_result_code',
                $qliroOrderManagementStatus['ProviderResultCode'] ?? null
            );
            $paymentTransaction->setAdditionalInformation(
                'provider_transaction_id',
                $qliroOrderManagementStatus['ProviderTransactionId'] ?? null
            );
            $paymentTransaction->setAdditionalInformation(
                'payment_reference',
                $qliroOrderManagementStatus['PaymentReference'] ?? null
            );

            $this->paymentTransactionRepository->save($paymentTransaction);
        } catch (\Exception $exception) {
            $this->logManager->debug(
                $exception,
                [
                    'extra' => [
                        'qliro_order_id' => $qliroOrderManagementStatus['OrderId'] ?? null,
                        'payment_id' => $payment->getId(),
                    ],
                ]
            );
            // Silent, since this code is not required, just nice to haves
        }
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     */
    public function handleCancelled(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        $this->setCanceled($qliroOrderManagementStatus, $omStatus, 'Cancelled');
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     */
    public function handleError(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        $this->setOnHold($qliroOrderManagementStatus, $omStatus, 'Error');
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     */
    public function handleInProcess(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        // Nothing to do
    }

    /**
     * The OnHold status is used when the capture is not successful and the order should be put on hold
     *
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     */
    public function handleOnHold(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        $this->setPendingPayment($qliroOrderManagementStatus, $omStatus, 'OnHold');
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     */
    public function handleUserInteraction(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        $this->setOnHold($qliroOrderManagementStatus, $omStatus, 'UserInteraction');
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     */
    public function handleCreated(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        // Nothing to do
    }

    /**
     * @param OrderManagementStatus $omStatus
     * @return \Magento\Sales\Model\Order\Payment
     */
    private function getPayment(OrderManagementStatus $omStatus): \Magento\Sales\Model\Order\Payment
    {
        return $this->paymentRepository->get($omStatus->getRecordId());
    }

    /**
     * Get payment transaction with the same transaction number as was part of this notification
     *
     * @param mixed $transactionId
     * @param int|null $paymentId
     * @param int|null $orderId
     * @return \Magento\Sales\Model\Order\Payment\Transaction
     */
    private function getPaymentTransaction(mixed $transactionId, mixed $paymentId, mixed $orderId): \Magento\Sales\Model\Order\Payment\Transaction
    {
        return $this->paymentTransactionRepository->getByTransactionId(
            $transactionId,
            $paymentId,
            $orderId
        );
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @param string $contextMessage
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     */
    private function setOnHold(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus, string $contextMessage): void
    {
        try {
            $payment = $this->getPayment($omStatus);
            $order = $payment->getOrder();
            $order->hold();
            $order->addStatusHistoryComment(
                __('Order set on hold because Qliro One reported an error with the capture: %1', $contextMessage)
            );
            $this->orderRepository->save($order);
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'qliro_order_id' => $qliroOrderManagementStatus['OrderId'] ?? null,
                    ],
                ]
            );

            throw new TerminalException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @param string $contextMessage
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     */
    private function setPendingPayment(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus, string $contextMessage): void
    {
        try {
            $payment = $this->getPayment($omStatus);
            $order = $payment->getOrder();
            if ($order->getState() == Order::STATE_PENDING_PAYMENT) {
                return;
            }
            $order->setState(Order::STATE_PENDING_PAYMENT);
            $order->addStatusHistoryComment(
                __('Order set to pending payment because Qliro One returned the capture with status: %1', $contextMessage)
            );
            $this->orderRepository->save($order);
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'qliro_order_id' => $qliroOrderManagementStatus['OrderId'] ?? null,
                    ],
                ]
            );

            throw new TerminalException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @param string $contextMessage
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     */
    private function setCanceled(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus, string $contextMessage): void
    {
        try {
            $payment = $this->getPayment($omStatus);
            $order = $payment->getOrder();
            if ($order->isCanceled()) {
                return;
            }
            $order->cancel();
            $order->addStatusHistoryComment(
                __('Order canceled because Qliro One returned the capture with status: %1', $contextMessage)
            );
            $this->orderRepository->save($order);
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'qliro_order_id' => $qliroOrderManagementStatus['OrderId'] ?? null,
                    ],
                ]
            );

            throw new TerminalException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }
}
