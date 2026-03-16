<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\Client\OrderManagement\OrderMutatorInterface;
use Qliro\QliroOne\Api\Data\AdminUpdateMerchantReferenceRequestInterface;
use Qliro\QliroOne\Api\Data\AdminOrderInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Payload\PayloadConverter;
use Qliro\QliroOne\Model\Exception\OrderPlacementPendingException;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Order\OrderPlacer;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromOrderConverter;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Service\RecurringPayments\Data as RecurringDataService;

/**
 * QliroOne management class
 */
class PlaceRecurringOrder
{
    /**
     * @var \Magento\Quote\Model\Quote|null
     */
    private ?\Magento\Quote\Model\Quote $currentQuote = null;

    /**
     * Class constructor
     *
     * @param Config $qliroConfig
     * @param MerchantInterface $merchantApi
     * @param OrderMutatorInterface $orderManagementApi
     * @param QuoteFromOrderConverter $quoteFromOrderConverter
     * @param LinkRepositoryInterface $linkRepository
     * @param CartRepositoryInterface $quoteRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param PayloadConverter $payloadConverter
     * @param LogManager $logManager
     * @param OrderPlacer $orderPlacer
     * @param OrderSender $orderSender
     * @param Quote $quoteManagement
     * @param Payment $paymentManagement
     * @param RecurringDataService $recurringDataService
     * @param \Magento\Quote\Api\CartManagementInterface $cartManagementInterface
     * @param OrderStateSetter $orderStateSetter
     */
    public function __construct(
        private readonly Config $qliroConfig,
        private readonly MerchantInterface $merchantApi,
        private readonly OrderMutatorInterface $orderManagementApi,
        private readonly QuoteFromOrderConverter $quoteFromOrderConverter,
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PayloadConverter $payloadConverter,
        private readonly LogManager $logManager,
        private readonly OrderPlacer $orderPlacer,
        private readonly OrderSender $orderSender,
        private readonly Quote $quoteManagement,
        private readonly Payment $paymentManagement,
        private readonly RecurringDataService $recurringDataService,
        protected readonly \Magento\Quote\Api\CartManagementInterface $cartManagementInterface,
        private readonly OrderStateSetter $orderStateSetter
    ) {
    }

    /**
     * Poll for Magento order placement and return order increment ID if successful
     *
     * @return \Magento\Sales\Model\Order
     * @throws TerminalException
     */
    public function poll(\Magento\Quote\Model\Quote $quote)
    {
        $quoteId = $quote->getId();

        try {
            $link = $this->linkRepository->getByQuoteId($quoteId);
            $this->logManager->setMerchantReference($link->getReference());

            if ($orderId = $link->getOrderId()) {
                return $this->orderRepository->get($orderId);
            }

            // Order not yet placed by the CheckoutStatus callback — caller should retry
            throw new OrderPlacementPendingException(__('Order has not been placed yet.'));

        } catch (OrderPlacementPendingException $exception) {
            throw $exception;
        } catch (\Exception $exception) {
            $this->logManager->critical($exception, [
                'extra' => ['quote_id' => $quoteId],
            ]);
            throw new TerminalException('Failed to retrieve recurring order', $exception->getCode(), $exception);
        }
    }


    /**
     * Set the quote for the next execute() call.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return self
     */
    public function setCurrentQuote(\Magento\Quote\Model\Quote $quote): self
    {
        $this->currentQuote = $quote;
        return $this;
    }

    /**
     * Get a QliroOne order, update the quote, then place Magento order
     * If placeOrder is successful, it returns the Magento Order
     * If an error occurs it returns null
     * If it's not possible to aquire lock, it returns false
     *
     * @param \Qliro\QliroOne\Api\Data\AdminOrderInterface $qliroOrder
     * @param string $state
     * @return \Magento\Sales\Model\Order
     * @throws TerminalException
     * @todo May require doing something upon $this->applyQliroOrderStatus($orderId) returning false
     */
    public function execute(AdminOrderInterface $qliroOrder, $state = Order::STATE_PENDING_PAYMENT)
    {
        $qliroOrderId = $qliroOrder->getOrderId();

        $this->logManager->setMark('PLACE ORDER');
        $order = null; // Placeholder, this method may never return null as an order

        try {
            $link = $this->linkRepository->getByQliroOrderId($qliroOrderId);

            try {
                if ($orderId = $link->getOrderId()) {
                    $this->logManager->debug(
                        'Order is already created, skipping',
                        [
                            'extra' => [
                                'qliro_order' => $qliroOrderId,
                                'quote_id' => $link->getQuoteId(),
                                'order_id' => $orderId,
                            ],
                        ]
                    );

                    $order = $this->orderRepository->get($orderId);
                } else {
                    $quote = $this->quoteRepository->get($link->getQuoteId());
                    $this->currentQuote = $quote;

                    $this->logManager->debug(
                        'Placing order',
                        [
                            'extra' => [
                                'qliro_order' => $qliroOrderId,
                                'quote_id' => $quote->getId(),
                            ],
                        ]
                    );

                    $this->quoteFromOrderConverter->convert(
                        $this->payloadConverter->toArray($qliroOrder),
                        $quote
                    );
                    $paymentTransactions = $qliroOrder->getPaymentTransactions();
                    foreach ($paymentTransactions as $paymentTransaction) {
                        $this->addAdditionalInfoToQuote($link, $paymentTransaction);
                    }
                    $this->addAdditionalShippingInfoToQuote($qliroOrder);
                    $this->quoteManagement->recalculateAndSaveQuote($quote);

                    $orderId = $this->cartManagementInterface->placeOrder($quote->getId());
                    $order = $this->orderRepository->get($orderId);

                    $link->setOrderId($orderId);
                    $this->linkRepository->save($link);

                    $this->paymentManagement->createPaymentTransaction($order, $qliroOrder, $state);

                    $this->logManager->debug(
                        'Order placed successfully',
                        [
                            'extra' => [
                                'qliro_order' => $qliroOrderId,
                                'quote_id' => $link->getQuoteId(),
                                'order_id' => $orderId,
                            ],
                        ]
                    );

                    $link->setMessage(sprintf('Created order %s', $order->getIncrementId()));
                    $this->linkRepository->save($link);
                }

                $this->applyQliroOrderStatus($order);
            } catch (\Exception $exception) {
                $link->setIsActive(false);
                $link->setMessage($exception->getMessage());
                $this->linkRepository->save($link);

                $this->logManager->critical(
                    $exception,
                    [
                        'extra' => [
                            'qliro_order_id' => $qliroOrderId,
                            'quote_id' => $link->getQuoteId(),
                        ],
                    ]
                );

                throw $exception;
            }
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'qliro_order_id' => $qliroOrderId,
                    ],
                ]
            );

            throw new TerminalException($exception->getMessage(), $exception->getCode(), $exception);
        } finally {
            $this->logManager->setMark(null);
        }

        return $order;
    }

    /**
     * Act on the order based on the qliro order status
     * It can be one of:
     * - Completed - the order can be shipped
     * - OnHold - review of buyer require more time
     * - Refused - deny the purchase
     *
     * @param Order $order
     * @return bool
     */
    public function applyQliroOrderStatus($order)
    {
        $orderId = $order->getId();

        try {
            $link = $this->linkRepository->getByOrderId($orderId);

            switch ($link->getQliroOrderStatus()) {
                case 'Completed':
                    $this->orderStateSetter->apply($order, Order::STATE_NEW);

                    if ($order->getCanSendNewEmailFlag() && !$order->getEmailSent()) {
                        try {
                            $this->orderSender->send($order);
                        } catch (\Exception $exception) {
                            $this->logManager->critical(
                                $exception,
                                [
                                    'extra' => [
                                        'order_id' => $orderId,
                                    ],
                                ]
                            );
                        }
                    }

                    /*
                     * If Magento order has already been placed and QliroOne order status is completed,
                     * the order merchant reference must be replaced with Magento order increment ID
                     */
                    /** @var \Qliro\QliroOne\Api\Data\AdminUpdateMerchantReferenceRequestInterface $request */
                    $request = $this->payloadConverter->fromArray(
                        [
                            'OrderId' => $link->getQliroOrderId(),
                            'NewMerchantReference' => $order->getIncrementId(),
                        ],
                        AdminUpdateMerchantReferenceRequestInterface::class
                    );

                    $response = $this->orderManagementApi->updateMerchantReference($request, $order->getStoreId());
                    $transactionId = 'unknown';
                    if ($response && $response->getPaymentTransactionId()) {
                        $transactionId = $response->getPaymentTransactionId();
                    }
                    $this->logManager->debug('New merchant reference was assigned to the Qliro One order', [
                        'payment_transaction_id' => $transactionId,
                        'qliro_order_id' => $link->getQliroOrderId(),
                        'order_id' => $order->getId(),
                        'new_merchant_reference' => $order->getIncrementId(),
                    ]);

                    break;

                case 'OnHold':
                    $this->orderStateSetter->apply($order, Order::STATE_PAYMENT_REVIEW);
                    break;

                case 'Refused':
                    // Deactivate link regardless of if the upcoming order cancellation successful or not
                    $link->setIsActive(false);
                    $link->setMessage(sprintf('Order #%s marked as canceled', $order->getIncrementId()));
                    $this->linkRepository->save($link);
                    $this->orderStateSetter->apply($order, Order::STATE_NEW);

                    if ($order->canCancel()) {
                        $order->cancel();
                        $this->orderRepository->save($order);
                    }

                    break;

                case 'InProcess':
                default:
                    return false;
            }

            return true;
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'order_id' => $orderId,
                    ],
                ]
            );

            return false;
        }
    }

    /**
     * Add information regarding this purchase to Quote, which will transfer to Order
     *
     * @param \Qliro\QliroOne\Api\Data\LinkInterface $link
     * @param \Qliro\QliroOne\Model\QliroOrder\Admin\OrderPaymentTransaction $paymentTransaction
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function addAdditionalInfoToQuote($link, $paymentTransaction)
    {
        // Note: called only from execute() where $this->currentQuote is set
        $payment = $this->currentQuote->getPayment();
        $payment->setAdditionalInformation(Config::QLIROONE_ADDITIONAL_INFO_QLIRO_ORDER_ID, $link->getQliroOrderId());
        $payment->setAdditionalInformation(Config::QLIROONE_ADDITIONAL_INFO_REFERENCE, $link->getReference());

        if ($paymentTransaction) {
            $payment->setAdditionalInformation(
                Config::QLIROONE_ADDITIONAL_INFO_PAYMENT_METHOD_CODE,
                $paymentTransaction->getType()
            );

            $payment->setAdditionalInformation(
                Config::QLIROONE_ADDITIONAL_INFO_PAYMENT_METHOD_NAME,
                $paymentTransaction->getPaymentMethodName()
            );
        }
    }

    /**
     * @param AdminOrderInterface $order
     * @return void
     */
    private function addAdditionalShippingInfoToQuote(AdminOrderInterface $order)
    {
        $payment = $this->currentQuote->getPayment();
        foreach ($order->getOrderItemActions() as $orderItem) {
            $metadata = $orderItem->getMetadata();
            $additionalShippingProperties = $metadata['AdditionalShippingProperties'] ?? false;
            if (!$additionalShippingProperties) {
                continue;
            }

            $payment->setAdditionalInformation(
                Config::QLIROONE_ADDITIONAL_INFO_SHIPPING_PROPERTIES,
                $additionalShippingProperties
            );
            return;
        }
    }
}
