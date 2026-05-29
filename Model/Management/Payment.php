<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\AdminReturnWithItemsRequestInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Api\Client\Exception\ClientException;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterfaceFactory;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterface;
use Qliro\QliroOne\Model\OrderManagementStatus;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\InvoiceMarkItemsAsShippedRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\ReturnWithItemsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\ShipmentMarkItemsAsShippedRequestBuilder;


/**
 * QliroOne management class
 */
class Payment
{
    /**
     * Payment additional data key used to skip the actual capture when invoicing.
     * Set by Shipment handler on the payment object to signal that capture was already
     * triggered via shipment and should not be triggered again via invoice.
     */
    public const string QLIRO_SKIP_ACTUAL_CAPTURE = 'qliro_skip_actual_capture';

    /**
     * Class constructor
     *
     * @param Config                                              $qliroConfig
     * @param OrderManagementInterface                            $orderManagementApi
     * @param LinkRepositoryInterface                             $linkRepository
     * @param OrderRepositoryInterface                            $orderRepository
     * @param LogManager                                          $logManager
     * @param BuilderInterface                                    $transactionBuilder
     * @param OrderManagementStatusInterfaceFactory               $orderManagementStatusInterfaceFactory
     * @param OrderManagementStatusRepositoryInterface            $orderManagementStatusRepository
     * @param InvoiceMarkItemsAsShippedRequestBuilder             $invoiceMarkItemsAsShippedRequestBuilder
     * @param ShipmentMarkItemsAsShippedRequestBuilder            $shipmentMarkItemsAsShippedRequestBuilder
     * @param ReturnWithItemsBuilder                              $returnWithItemsBuilder
     */
    public function __construct(
        private readonly Config                                   $qliroConfig,
        private readonly OrderManagementInterface                 $orderManagementApi,
        private readonly LinkRepositoryInterface                  $linkRepository,
        private readonly OrderRepositoryInterface                 $orderRepository,
        private readonly LogManager                               $logManager,
        private readonly BuilderInterface                         $transactionBuilder,
        private readonly OrderManagementStatusInterfaceFactory    $orderManagementStatusInterfaceFactory,
        private readonly OrderManagementStatusRepositoryInterface $orderManagementStatusRepository,
        private readonly InvoiceMarkItemsAsShippedRequestBuilder  $invoiceMarkItemsAsShippedRequestBuilder,
        private readonly ShipmentMarkItemsAsShippedRequestBuilder $shipmentMarkItemsAsShippedRequestBuilder,
        private readonly ReturnWithItemsBuilder                   $returnWithItemsBuilder
    ) {
    }

    /**
     * Create a payment transaction, which will hold and handle the Order Management features.
     * This saves payment and transaction, possibly also the order.
     *
     * This should have been done differently, with authorization keyword in method etc...
     *
     * @param Order $order
     * @param array $qliroOrder  Raw Qliro order array
     * @param string $state
     * @throws \Exception
     */
    public function createPaymentTransaction(Order $order, array $qliroOrder, string $state = Order::STATE_PENDING_PAYMENT): void
    {
        $this->logManager->setMark('PAYMENT TRANSACTION');

        try {
            /** @var Order\Payment $payment */
            $payment = $order->getPayment();

            if ($payment === null) {
                throw new LocalizedException(
                    __('Cannot create payment transaction: order %1 has no payment.', $order->getIncrementId())
                );
            }

            $payment->setLastTransId($qliroOrder['OrderId'] ?? null);
            $transactionId = 'qliroone-' . ($qliroOrder['OrderId'] ?? 'unknown');
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

            if ($order->getState() != $state) {
                $order->setState($state);
                $this->orderRepository->save($order);
            }

            $transaction->save();
        } catch (\Exception $exception) {
            throw $exception;
        } finally {
            $this->logManager->setMark(null);
        }
    }

    /**
     * @param InfoInterface $payment
     * @param float $amount
     * @return void
     * @throws \Exception
     */
    public function captureByInvoice(InfoInterface $payment, float $amount): void
    {
        if ($payment->getData(self::QLIRO_SKIP_ACTUAL_CAPTURE)) {
            $this->logManager->debug('captureByInvoice — skipped (QLIRO_SKIP_ACTUAL_CAPTURE is set)', [
                'extra' => [
                    'order_id'    => $payment->getOrder()->getId(),
                    'payment_id'  => $payment->getId(),
                ],
            ]);
            return;
        }

        /** @var Order $order */
        $order = $payment->getOrder();
        $link = $this->linkRepository->getByOrderId($order->getId());
        $this->logManager->setMerchantReference($link->getReference());

        $this->logManager->debug('captureByInvoice — calling MarkItemsAsShipped', [
            'extra' => [
                'order_id'      => $order->getId(),
                'qliro_order_id' => $link->getQliroOrderId(),
                'amount'        => $amount,
                'payment_txn_id' => $payment->getTransactionId(),
            ],
        ]);

        $this->invoiceMarkItemsAsShippedRequestBuilder->setPayment($payment);
        $this->invoiceMarkItemsAsShippedRequestBuilder->setAmount($amount);

        $request = $this->invoiceMarkItemsAsShippedRequestBuilder->create();
        $result = $this->orderManagementApi->markItemsAsShipped($request, $order->getStoreId());

        $this->logManager->debug('captureByInvoice — MarkItemsAsShipped response', [
            'extra' => [
                'order_id'               => $order->getId(),
                'qliro_order_id'         => $link->getQliroOrderId(),
                'result_status'          => $result->getStatus(),
                'payment_transaction_id' => $result->getPaymentTransactionId(),
            ],
        ]);

        try {
            /** @var OrderManagementStatus $omStatus */
            $omStatus = $this->orderManagementStatusInterfaceFactory->create();
            $omStatus->setRecordId($payment->getId());
            $omStatus->setRecordType(OrderManagementStatusInterface::RECORD_TYPE_PAYMENT);
            $omStatus->setTransactionId($result->getPaymentTransactionId());
            $omStatus->setTransactionStatus('Created');
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
                $this->logManager->debug('captureByInvoice — transaction ID set on payment', [
                    'extra' => [
                        'order_id'               => $order->getId(),
                        'payment_transaction_id' => $result->getPaymentTransactionId(),
                        'is_transaction_pending'  => $payment->getIsTransactionPending(),
                    ],
                ]);
            } else {
                $this->logManager->debug('captureByInvoice — status Created but PaymentTransactionId is empty', [
                    'extra' => ['order_id' => $order->getId()],
                ]);
            }
        } else {
            $this->logManager->debug('captureByInvoice — unexpected status, throwing exception', [
                'extra' => [
                    'order_id'      => $order->getId(),
                    'result_status' => $result->getStatus(),
                ],
            ]);
            throw new LocalizedException(
                __('Unable to capture payment for this order.')
            );
        }
    }

    /**
     * @param Shipment $shipment
     * @return void
     * @throws \Exception
     */
    public function captureByShipment(Shipment $shipment): void
    {
        if (!$this->qliroConfig->shouldCaptureOnShipment($shipment->getStoreId())) {
            return;
        }

        /** @var Order $order */
        $order = $shipment->getOrder();
        $link = $this->linkRepository->getByOrderId($order->getId());
        $this->logManager->setMerchantReference($link->getReference());

        $this->shipmentMarkItemsAsShippedRequestBuilder->setShipment($shipment);
        $request = $this->shipmentMarkItemsAsShippedRequestBuilder->create();

        if (count($request->getShipments()) == 0) {
            return;
        }

        $result = $this->orderManagementApi->markItemsAsShipped($request, $order->getStoreId());

        try {
            /** @var OrderManagementStatus $omStatus */
            $omStatus = $this->orderManagementStatusInterfaceFactory->create();

            $omStatus->setRecordId($shipment->getId());
            $omStatus->setRecordType(OrderManagementStatusInterface::RECORD_TYPE_SHIPMENT);
            $omStatus->setTransactionId($result->getPaymentTransactionId());
            $omStatus->setTransactionStatus('Created');
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
     * @param Order\Payment $payment
     * @param float $amount
     * @return void
     * @throws LocalizedException
     */
    public function refundByInvoice(Order\Payment $payment, float $amount): void
    {
        if (!$amount) {
            throw new LocalizedException(__('Zero amount is not allowed.'));
        }

        try {
            $link = $this->linkRepository->getByOrderId($payment->getOrder()->getId());

            $request = $this->returnWithItemsBuilder
                ->setPayment($payment)
                ->create();

            if (!$this->isValidRequestAmount($request, $amount)) {
                throw new LocalizedException(__('Request amount is not valid.'));
            }


            $result = $this->orderManagementApi->returnWithItems($request, $payment->getOrder()->getStoreId());

            try {
                /** @var OrderManagementStatus $omStatus */
                $omStatus = $this->orderManagementStatusInterfaceFactory->create();

                $omStatus->setRecordId($payment->getId());
                $omStatus->setRecordType(OrderManagementStatusInterface::RECORD_TYPE_REFUND);
                $omStatus->setTransactionId($result->getPaymentTransactionId());
                $omStatus->setTransactionStatus('Created');
                $omStatus->setNotificationStatus(OrderManagementStatusInterface::NOTIFICATION_STATUS_DONE);
                $omStatus->setMessage('Refund Requested');
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
                    __('Unable refund items')
                );
            }
        } catch (ClientException $e) {
            $this->logManager->debug(
                $e,
                [
                    'extra' => [
                        'order_id' => $payment->getOrder()->getId(),
                        'quote_id' => $payment->getOrder()->getQuoteId(),
                    ],
                ]
            );

            throw new LocalizedException(
                __('Unable refund items')
            );
        }
    }

    /**
     * Validate return request items and requested amount
     *
     * @param AdminReturnWithItemsRequestInterface $request
     * @param float $amount
     * @return bool
     */
    private function isValidRequestAmount(AdminReturnWithItemsRequestInterface $request, float $amount): bool
    {
        $returns = $request->getReturns();
        if (!count($returns)) {
            return false;
        }

        $sum = 0;
        foreach ($returns as $type => $return) {
            if (is_array($return) && isset($return['PricePerItemIncVat'])) {
                $sum += $return['PricePerItemIncVat'] * $return['Quantity'];
                continue;
            }

            if (!is_array($return)) {
                continue;
            }

            foreach ($return as $inner) {
                if (is_array($inner) && isset($inner['PricePerItemIncVat'])) {
                    $innerSum = $inner['PricePerItemIncVat'] * $inner['Quantity'];
                    switch ($type) {
                        case 'Fees':
                            $innerSum = -abs($innerSum);
                            break;
                        default:
                            $innerSum = abs($innerSum);
                            break;
                    }

                    $sum += $innerSum;
                }
            }
        }

        if (($sum * 100) != ($amount * 100)) { // fix php double type comparison issue
            return false;
        }

        return true;
    }
}
