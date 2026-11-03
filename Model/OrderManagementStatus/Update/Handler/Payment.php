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
use Magento\Framework\App\ObjectManager;
use Magento\Sales\Model\Order;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\Notification\QliroOrderManagementStatus;
use Qliro\QliroOne\Model\OrderManagementStatus;
use Qliro\QliroOne\Model\OrderManagementStatus\Update\CaptureHold;
use Qliro\QliroOne\Model\OrderManagementStatus\Update\CaptureTransactionUpdater;
use Qliro\QliroOne\Model\Order\StateStatus;

class Payment implements OrderManagementStatusUpdateHandlerInterface
{
    /**
     * @var OrderPaymentRepositoryInterface
     */
    private $paymentRepository;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var Manager
     */
    private $logManager;

    /**
     * @var CaptureTransactionUpdater
     */
    private $captureTransactionUpdater;

    /**
     * @var CaptureHold
     */
    private $captureHold;

    /**
     * @var StateStatus
     */
    private StateStatus $stateStatus;

    /**
     * Payment constructor.
     * @param OrderPaymentRepositoryInterface $paymentRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param Manager $logManager
     * @param CaptureTransactionUpdater $captureTransactionUpdater
     * @param CaptureHold $captureHold
     * @param StateStatus|null $stateStatus
     */
    public function __construct(
        OrderPaymentRepositoryInterface $paymentRepository,
        OrderRepositoryInterface $orderRepository,
        Manager $logManager,
        CaptureTransactionUpdater $captureTransactionUpdater,
        CaptureHold $captureHold,
        ?StateStatus $stateStatus = null
    ) {
        $this->paymentRepository = $paymentRepository;
        $this->orderRepository = $orderRepository;
        $this->logManager = $logManager;
        $this->captureTransactionUpdater = $captureTransactionUpdater;
        $this->captureHold = $captureHold;
        $this->stateStatus = $stateStatus ?? ObjectManager::getInstance()->get(StateStatus::class);
    }

    /**
     * @param QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @throws TerminalException
     */
    public function handleSuccess($qliroOrderManagementStatus, $omStatus)
    {
        $payment = $this->getPayment($omStatus);
        $order = $payment->getOrder();

        /*
         * Update Order
         */
        try {
            $this->captureHold->release($order, $qliroOrderManagementStatus->getPaymentTransactionId());

            $formattedPrice = $order->getBaseCurrency()->formatTxt(
                $qliroOrderManagementStatus->getAmount()
            );

            $order->addStatusHistoryComment(__('Capture of %1 confirmed successful', $formattedPrice));

            $this->orderRepository->save($order);
        } catch (\Exception $exception) {
            $this->logManager->debug(
                $exception,
                [
                    'extra' => [
                        'qliro_order_id' => $qliroOrderManagementStatus->getOrderId(),
                        'payment_id' => $payment->getId(),
                    ],
                ]
            );
            throw new TerminalException('Could not handle Invoice Success', (int)$exception->getCode(), $exception);
        }

        $this->captureTransactionUpdater->update(
            $qliroOrderManagementStatus,
            $payment->getId(),
            $order->getId()
        );
    }

    /**
     * @param QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @throws TerminalException
     */
    public function handleCancelled($qliroOrderManagementStatus, $omStatus)
    {
        $this->setCanceled($qliroOrderManagementStatus, $omStatus, 'Cancelled');
    }

    /**
     * @param QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @throws TerminalException
     */
    public function handleError($qliroOrderManagementStatus, $omStatus)
    {
        $this->setOnHold($qliroOrderManagementStatus, $omStatus, 'Error');
    }

    /**
     * @param QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     */
    public function handleInProcess($qliroOrderManagementStatus, $omStatus)
    {
        // Nothing to do
    }

    /**
     * The OnHold status is used when the capture is not successful and the order should be put on hold
     * @param QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @throws TerminalException
     */
    public function handleOnHold($qliroOrderManagementStatus, $omStatus)
    {
        $this->setPendingPayment($qliroOrderManagementStatus, $omStatus, 'OnHold');
    }

    /**
     * @param QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @throws TerminalException
     */
    public function handleUserInteraction($qliroOrderManagementStatus, $omStatus)
    {
        $this->setOnHold($qliroOrderManagementStatus, $omStatus, 'UserInteraction');
    }

    /**
     * @param QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     */
    public function handleCreated($qliroOrderManagementStatus, $omStatus)
    {
        // Nothing to do
    }

    /**
     * @param OrderManagementStatus $omStatus
     * @return \Magento\Sales\Model\Order\Payment $payment
     */
    private function getPayment($omStatus)
    {
        $payment = $this->paymentRepository->get($omStatus->getRecordId());

        return $payment;
    }

    /**
     * @param QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @param string $contextMessage
     * @throws TerminalException
     */
    private function setOnHold($qliroOrderManagementStatus, $omStatus, $contextMessage)
    {
        try {
            $payment = $this->getPayment($omStatus);
            $order = $payment->getOrder();
            $transactionId = $qliroOrderManagementStatus->getPaymentTransactionId();
            if (!$this->captureHold->hold($order, $transactionId, $contextMessage)) {
                $order->addStatusHistoryComment(__(
                    'Qliro One reported %1 for capture %2, which already succeeded, so it is ignored',
                    $contextMessage,
                    $transactionId
                ));
            }
            $this->orderRepository->save($order);
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'qliro_order_id' => $qliroOrderManagementStatus->getOrderId(),
                    ],
                ]
            );

            throw new TerminalException($exception->getMessage(), (int)$exception->getCode(), $exception);
        }
    }


    /**
     * @param QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @param string $contextMessage
     * @throws TerminalException
     */
    private function setPendingPayment($qliroOrderManagementStatus, $omStatus, $contextMessage)
    {
        try {
            $payment = $this->getPayment($omStatus);
            $order = $payment->getOrder();
            if ($this->stateStatus->currentState($order) === Order::STATE_PENDING_PAYMENT) {
                return;
            }
            // A held order keeps its hold and returns to pending payment when released
            $held = $order->getState() === Order::STATE_HOLDED;
            $this->stateStatus->setKeepingHold($order, Order::STATE_PENDING_PAYMENT);
            $order->addStatusHistoryComment($held
                ? __(
                    'Qliro One returned the capture with status: %1, the order stays on hold',
                    $contextMessage
                )
                : __(
                    'Order set to pending payment because Qliro One returned the capture with status: %1',
                    $contextMessage
                ));
            $this->orderRepository->save($order);
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'qliro_order_id' => $qliroOrderManagementStatus->getOrderId(),
                    ],
                ]
            );

            throw new TerminalException($exception->getMessage(), (int)$exception->getCode(), $exception);
        }
    }

    /**
     * @param QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @param string $contextMessage
     * @throws TerminalException
     */
    private function setCanceled($qliroOrderManagementStatus, $omStatus, $contextMessage)
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
                        'qliro_order_id' => $qliroOrderManagementStatus->getOrderId(),
                    ],
                ]
            );

            throw new TerminalException($exception->getMessage(), (int)$exception->getCode(), $exception);
        }
    }
}
