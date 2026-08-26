<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\QliroOrderInterface;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Api\Client\Exception\ClientException;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterfaceFactory;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterface;
use Qliro\QliroOne\Model\OrderManagementStatus;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\InvoiceMarkItemsAsShippedRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\ShipmentMarkItemsAsShippedRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\AddItemsToInvoiceBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\CaptureRefundAllocator;
use Qliro\QliroOne\Model\QliroOrder\Admin\SequentialRefundProcessor;
/**
 * QliroOne management class
 */
class Payment extends AbstractManagement
{
    /**
     * @var \Qliro\QliroOne\Model\Config
     */
    private $qliroConfig;

    /**
     * @var \Qliro\QliroOne\Api\Client\OrderManagementInterface
     */
    private $orderManagementApi;

    /**
     * @var \Qliro\QliroOne\Api\LinkRepositoryInterface
     */
    private $linkRepository;

    /**
     * @var \Qliro\QliroOne\Model\Logger\Manager
     */
    private $logManager;

    /**
     * @var \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
     */
    private $transactionBuilder;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var \Qliro\QliroOne\Api\Data\OrderManagementStatusInterfaceFactory
     */
    private $orderManagementStatusInterfaceFactory;

    /**
     * @var OrderManagementStatusRepositoryInterface
     */
    private $orderManagementStatusRepository;

    /**
     * @var \Qliro\QliroOne\Model\QliroOrder\Admin\Builder\InvoiceMarkItemsAsShippedRequestBuilder
     */
    private $invoiceMarkItemsAsShippedRequestBuilder;

    /**
     * @var \Qliro\QliroOne\Model\QliroOrder\Admin\Builder\ShipmentMarkItemsAsShippedRequestBuilder
     */
    private $shipmentMarkItemsAsShippedRequestBuilder;

    /**
     * @var AddItemsToInvoiceBuilder
     */
    private $addItemsToInvoiceBuilder;

    /**
     * @var CaptureRefundAllocator
     */
    private $captureRefundAllocator;

    /**
     * @var SequentialRefundProcessor
     */
    private $sequentialRefundProcessor;

    /**
     * Inject dependencies
     *
     * @param Config $qliroConfig
     * @param OrderManagementInterface $orderManagementApi
     * @param LinkRepositoryInterface $linkRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param LogManager $logManager
     * @param BuilderInterface $transactionBuilder
     * @param OrderManagementStatusInterfaceFactory $orderManagementStatusInterfaceFactory
     * @param OrderManagementStatusRepositoryInterface $orderManagementStatusRepository
     * @param InvoiceMarkItemsAsShippedRequestBuilder $invoiceMarkItemsAsShippedRequestBuilder
     * @param ShipmentMarkItemsAsShippedRequestBuilder $shipmentMarkItemsAsShippedRequestBuilder
     * @param AddItemsToInvoiceBuilder $addItemsToInvoiceBuilder
     * @param CaptureRefundAllocator $captureRefundAllocator
     * @param SequentialRefundProcessor $sequentialRefundProcessor
     */
    public function __construct(
        Config $qliroConfig,
        OrderManagementInterface $orderManagementApi,
        LinkRepositoryInterface $linkRepository,
        OrderRepositoryInterface $orderRepository,
        LogManager $logManager,
        BuilderInterface $transactionBuilder,
        OrderManagementStatusInterfaceFactory $orderManagementStatusInterfaceFactory,
        OrderManagementStatusRepositoryInterface $orderManagementStatusRepository,
        InvoiceMarkItemsAsShippedRequestBuilder $invoiceMarkItemsAsShippedRequestBuilder,
        ShipmentMarkItemsAsShippedRequestBuilder $shipmentMarkItemsAsShippedRequestBuilder,
        AddItemsToInvoiceBuilder $addItemsToInvoiceBuilder,
        CaptureRefundAllocator $captureRefundAllocator,
        SequentialRefundProcessor $sequentialRefundProcessor
    ) {
        $this->qliroConfig = $qliroConfig;
        $this->orderManagementApi = $orderManagementApi;
        $this->linkRepository = $linkRepository;
        $this->logManager = $logManager;
        $this->transactionBuilder = $transactionBuilder;
        $this->orderRepository = $orderRepository;
        $this->orderManagementStatusInterfaceFactory = $orderManagementStatusInterfaceFactory;
        $this->orderManagementStatusRepository = $orderManagementStatusRepository;
        $this->invoiceMarkItemsAsShippedRequestBuilder = $invoiceMarkItemsAsShippedRequestBuilder;
        $this->shipmentMarkItemsAsShippedRequestBuilder = $shipmentMarkItemsAsShippedRequestBuilder;
        $this->addItemsToInvoiceBuilder = $addItemsToInvoiceBuilder;
        $this->captureRefundAllocator = $captureRefundAllocator;
        $this->sequentialRefundProcessor = $sequentialRefundProcessor;
    }

    /**
     * Create payment transaction, which will hold and handle the Order Management features.
     * This saves payment and transaction, possibly also the order.
     *
     * This should have been done differently, with authorization keyword in method etc...
     *
     * @param Order $order
     * @param QliroOrderInterface $qliroOrder
     * @param string $state
     * @throws \Exception
     */
    public function createPaymentTransaction($order, $qliroOrder, $state = Order::STATE_PENDING_PAYMENT)
    {
        $this->logManager->setMark('PAYMENT TRANSACTION');

        try {
            /** @var \Magento\Sales\Model\Order\Payment $payment */
            $payment = $order->getPayment();

            $payment->setLastTransId($qliroOrder->getOrderId());
            $transactionId = 'qliroone-' . $qliroOrder->getOrderId();
            $payment->setTransactionId($transactionId);
            $payment->setIsTransactionClosed(false);

            $formattedPrice = $order->getBaseCurrency()->formatTxt(
                $order->getGrandTotal()
            );

            $message = __('Qliro One authorized amount of %1.', $formattedPrice);

            /** @var \Magento\Sales\Api\Data\TransactionInterface $transaction */
            $transaction = $this->transactionBuilder->setPayment($payment)->setOrder($order)->setTransactionId(
                $payment->getTransactionId()
            )->build(\Magento\Sales\Api\Data\TransactionInterface::TYPE_AUTH);

            $payment->addTransactionCommentsToOrder($transaction, $message);
            $payment->setSkipOrderProcessing(true);
            $payment->save();

            if (empty($status)) {
                if ($order->getState() != $state) {
                    $order->setState($state);
                    $this->orderRepository->save($order);
                }
            } else {
                if ($order->getState() != $state || $order->getStatus() != $status) {
                    $order->setState($state)->setStatus($status);
                    $this->orderRepository->save($order);
                }
            }

            $transaction->save();
        } catch (\Exception $exception) {
            throw $exception;
        } finally {
            $this->logManager->setMark(null);
        }
    }

    /**
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return void
     * @throws \Exception
     */
    public function captureByInvoice($payment, $amount)
    {
        if ($payment->getData(self::QLIRO_SKIP_ACTUAL_CAPTURE)) {
            return;
        }

        /** @var Order $order */
        $order = $payment->getOrder();

        if ($this->hasSubmittedCapture($order)) {
            return;
        }

        $link = $this->linkRepository->getByOrderId($order->getId());
        $this->logManager->setMerchantReference($link->getReference());

        $this->invoiceMarkItemsAsShippedRequestBuilder->setPayment($payment);
        $this->invoiceMarkItemsAsShippedRequestBuilder->setAmount($amount);

        $request = $this->invoiceMarkItemsAsShippedRequestBuilder->create();
        $order->setData(self::QLIRO_CAPTURE_SUBMITTED, true);

        try {
            $result = $this->orderManagementApi->markItemsAsShipped($request, $order->getStoreId());
        } catch (ClientException $exception) {
            if ($this->isAlreadyShipped($exception)) {
                $this->logAlreadyShipped($order, 'invoice');

                return;
            }

            throw $this->describeCaptureFailure($exception, $order, $link->getQliroOrderId());
        }

        try {
            /** @var OrderManagementStatus $omStatus */
            $omStatus = $this->orderManagementStatusInterfaceFactory->create();
            $omStatus->setRecordId($payment->getId());
            $omStatus->setRecordType(OrderManagementStatusInterface::RECORD_TYPE_PAYMENT);
            $omStatus->setTransactionId($result->getPaymentTransactionId());
            $omStatus->setTransactionStatus(QliroOrderManagementStatusInterface::STATUS_CREATED);
            $omStatus->setNotificationStatus(OrderManagementStatusInterface::NOTIFICATION_STATUS_DONE);
            $omStatus->setMessage('Capture Requested for Invoice');
            $omStatus->setQliroOrderId($link->getQliroOrderId());

            $this->orderManagementStatusRepository->save($omStatus);
        } catch (\Exception $exception) {
            $this->logManager->debug(
                $exception,
                [
                    'extra' => [
                        'payment_id' => $payment->getId(),
                    ],
                ]
            );
        }

        if ($result->getStatus() == 'Created') {
            if ($result->getPaymentTransactionId()) {
                $payment->setTransactionId($result->getPaymentTransactionId());
            }
        } else {
            throw new LocalizedException(
                __('Unable to capture payment for this order.')
            );
        }
    }

    /**
     * Whether this module already sent a capture for this order in the current request.
     *
     * capture_on_shipment and capture_on_invoice are independent settings and one admin action can
     * fire both paths. Qliro stamps a fresh RequestId per call, so it cannot recognise the second
     * submission as a repeat of the first; it sees a new shipment request against a reservation
     * that has nothing left and refuses it (PLIN-381).
     *
     * @param Order $order
     * @return bool
     */
    private function hasSubmittedCapture(Order $order)
    {
        if (!$order->getData(self::QLIRO_CAPTURE_SUBMITTED)) {
            return false;
        }

        $this->logManager->debug(
            'Skipping capture, one was already submitted for this order in this request',
            [
                'extra' => [
                    'order_id' => $order->getId(),
                ],
            ]
        );

        return true;
    }

    /**
     * Whether Qliro refused because the reservation has nothing left to ship, i.e. the capture
     * this module is asking for already happened.
     *
     * @param ClientException $exception
     * @return bool
     */
    private function isAlreadyShipped(ClientException $exception)
    {
        return $this->qliroErrorCode($exception) === self::QLIRO_ERROR_NO_ITEMS_LEFT;
    }

    /**
     * The money already moved, so the Magento document must be allowed to complete. Throwing here
     * rolled the invoice back and left the order permanently unclosable: the reservation is spent,
     * so every retry is refused the same way (PLIN-381).
     *
     * @param Order $order
     * @param string $trigger
     * @return void
     */
    private function logAlreadyShipped(Order $order, $trigger)
    {
        $this->logManager->info(
            'Capture was already registered at Qliro, treating it as done',
            [
                'extra' => [
                    'order_id' => $order->getId(),
                    'trigger' => $trigger,
                ],
            ]
        );
    }

    /**
     * Turn a capture refusal into something an operator can act on. Every failure used to surface
     * as "Request to Qliro One has failed", which does not distinguish an order Qliro has never
     * heard of from a network problem (PLIN-381).
     *
     * @param ClientException $exception
     * @param Order $order
     * @param string|int|null $qliroOrderId
     * @return \Magento\Framework\Exception\LocalizedException
     */
    private function describeCaptureFailure(ClientException $exception, Order $order, $qliroOrderId)
    {
        if ($this->qliroErrorCode($exception) !== self::QLIRO_ERROR_ORDER_NOT_FOUND) {
            return $exception;
        }

        $this->logManager->critical(
            'Qliro does not know the order id stored for this Magento order',
            [
                'extra' => [
                    'order_id' => $order->getId(),
                    'qliro_order_id' => $qliroOrderId,
                ],
            ]
        );

        return new LocalizedException(
            __(
                'Qliro does not know order %1, which is the Qliro order stored for this order. '
                . 'This usually means it was created against the other Qliro environment, so it '
                . 'cannot be captured here. Retrying will not help.',
                $qliroOrderId
            ),
            $exception
        );
    }

    /**
     * @param ClientException $exception
     * @return string|null
     */
    private function qliroErrorCode(ClientException $exception)
    {
        $previous = $exception->getPrevious();

        return $previous instanceof TerminalException ? $previous->getQliroErrorCode() : null;
    }

    /**
     * @param \Magento\Sales\Model\Order\Shipment $shipment
     * @return void
     * @throws \Exception
     */
    public function captureByShipment($shipment)
    {
        if (!$this->qliroConfig->shouldCaptureOnShipment($shipment->getStoreId())) {
            return;
        }

        /** @var Order $order */
        $order = $shipment->getOrder();

        if ($this->hasSubmittedCapture($order)) {
            return;
        }

        $link = $this->linkRepository->getByOrderId($order->getId());
        $this->logManager->setMerchantReference($link->getReference());

        $this->shipmentMarkItemsAsShippedRequestBuilder->setShipment($shipment);
        $request = $this->shipmentMarkItemsAsShippedRequestBuilder->create();

        if (count($request->getShipments()) == 0) {
            return;
        }

        $order->setData(self::QLIRO_CAPTURE_SUBMITTED, true);

        try {
            $result = $this->orderManagementApi->markItemsAsShipped($request, $order->getStoreId());
        } catch (ClientException $exception) {
            if ($this->isAlreadyShipped($exception)) {
                $this->logAlreadyShipped($order, 'shipment');

                return;
            }

            throw $this->describeCaptureFailure($exception, $order, $link->getQliroOrderId());
        }

        try {
            /** @var OrderManagementStatus $omStatus */
            $omStatus = $this->orderManagementStatusInterfaceFactory->create();

            $omStatus->setRecordId($shipment->getId());
            $omStatus->setRecordType(OrderManagementStatusInterface::RECORD_TYPE_SHIPMENT);
            $omStatus->setTransactionId($result->getPaymentTransactionId());
            $omStatus->setTransactionStatus(QliroOrderManagementStatusInterface::STATUS_CREATED);
            $omStatus->setNotificationStatus(OrderManagementStatusInterface::NOTIFICATION_STATUS_DONE);
            $omStatus->setMessage('Capture Requested for Shipment');
            $omStatus->setQliroOrderId($link->getQliroOrderId());

            $this->orderManagementStatusRepository->save($omStatus);
        } catch (\Exception $exception) {
            $this->logManager->debug(
                $exception,
                [
                    'extra' => [
                        'shipment_id' => $shipment->getId(),
                    ],
                ]
            );
        }

        if ($result->getStatus() != 'Created') {
            throw new LocalizedException(
                __('Unable to mark items as shipped.')
            );
        }
    }

    /**
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param $amount
     * @return void
     * @throws LocalizedException
     */
    public function addItemsToInvoice($payment)
    {
        $link = $this->linkRepository->getByOrderId($payment->getOrder()->getId());
        $this->logManager->setMerchantReference($link->getReference());

        try {
            // Split the refund across captures (Qliro validates each Addition against its own
            // capture). Empty allocation = no captured-amount data, use single-Addition fallback.
            $creditMemo = $payment->getCreditmemo();
            $refundAmount = round(abs((float)$creditMemo->getGrandTotal()), 2);
            $allocation = $this->captureRefundAllocator->allocate($payment, $refundAmount);

            if (empty($allocation)) {
                $this->sendSingleAdditionRefund($payment, $link);

                return;
            }

            // PSP accepts only one in-flight return at a time, so send the first Addition and
            // queue the rest for the success callback. VAT is embedded per entry (no credit memo
            // available later).
            $vatRate = $this->addItemsToInvoiceBuilder->getRefundVatRate($payment);

            foreach ($allocation as &$entry) {
                $entry['vat_rate'] = $vatRate;
            }
            unset($entry);

            $this->sequentialRefundProcessor->start($payment, $allocation);

        } catch (InputException $e) {
            $this->logManager->debug(
                $e->getMessage(),
                [
                    'extra' => [
                        'order_id' => $payment->getOrder()->getId(),
                    ],
                ]
            );
        }
    }

    /**
     * Send a single Addition for the full credit memo amount against the payment's parent
     * transaction. Used when no per-capture allocation is available.
     *
     * @param \Magento\Sales\Model\Order\Payment $payment
     * @param \Qliro\QliroOne\Api\Data\LinkInterface $link
     * @return void
     * @throws LocalizedException
     */
    private function sendSingleAdditionRefund($payment, $link)
    {
        $request = $this->addItemsToInvoiceBuilder
            ->setPayment($payment)
            ->setAllocation([])
            ->create();

        $results = $this->orderManagementApi->addItemsToInvoice($request, $payment->getOrder()->getStoreId());

        if (empty($results)) {
            throw new LocalizedException(
                __('Unable to refund')
            );
        }

        foreach ($results as $result) {
            try {
                /** @var OrderManagementStatus $omStatus */
                $omStatus = $this->orderManagementStatusInterfaceFactory->create();

                $omStatus->setRecordId($payment->getId());
                $omStatus->setRecordType(OrderManagementStatusInterface::ADD_ITEMS_TO_INVOICE);
                $omStatus->setTransactionId($result->getPaymentTransactionId());
                $omStatus->setTransactionStatus(QliroOrderManagementStatusInterface::STATUS_CREATED);
                $omStatus->setNotificationStatus(OrderManagementStatusInterface::NOTIFICATION_STATUS_DONE);
                $omStatus->setMessage('Refund requested with add items to invoice');
                $omStatus->setQliroOrderId($link->getQliroOrderId());

                $this->orderManagementStatusRepository->save($omStatus);
            } catch (\Exception $exception) {
                $this->logManager->debug(
                    $exception,
                    [
                        'extra' => [
                            'payment_id' => $payment->getId(),
                        ],
                    ]
                );
            }

            if ($result->getStatus() != 'Created') {
                throw new LocalizedException(
                    __('Unable to refund')
                );
            }
        }
    }

}
