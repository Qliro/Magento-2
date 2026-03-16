<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Management;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote as MagentoQuote;
use Magento\Sales\Api\OrderRepositoryInterface;
use Qliro\QliroOne\Model\Management\Quote as QuoteManagement;
use Magento\Sales\Model\Order;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\Client\OrderManagement\OrderMutatorInterface;
use Qliro\QliroOne\Api\Data\AdminUpdateMerchantReferenceRequestInterface;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Order\OrderAddressUpdater;
use Qliro\QliroOne\Model\Order\OrderItemsSyncer;
use Qliro\QliroOne\Model\Order\OrderPlacer;
use Qliro\QliroOne\Model\Order\OrderShippingMethodSyncer;
use Qliro\QliroOne\Model\Payload\PayloadConverter;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromOrderConverter;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Qliro\QliroOne\Service\RecurringPayments\Data as RecurringDataService;

/**
 * Places Magento orders from Qliro order data received via callback.
 *
 * Flow:
 *  1. placePending()      — called from OrderService::getQliroOrder() once the quote is hydrated.
 *                           Creates the Magento order in STATE_PENDING_PAYMENT and saves
 *                           order_id on the link record immediately.
 *  2. hydrateAndFinalise() — called from CheckoutStatus::update() when Qliro's callback
 *                           arrives. Updates the order address/customer data with the
 *                           confirmed values from the Qliro response, creates the payment
 *                           transaction, and delegates to applyQliroOrderStatus() to move
 *                           the order to its terminal state.
 */
class PlaceOrder
{
    /**
     * Holds the quote for the current placePending() call.
     * Set at the start of placePending() and used by the private helper methods
     * called within the same request. This replaces the shared mutable state
     * that was previously provided via the old AbstractManagement pattern.
     *
     * @var MagentoQuote|null
     */
    private ?MagentoQuote $currentQuote = null;

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
     * @param QuoteManagement $quoteManagement
     * @param Payment $paymentManagement
     * @param RecurringDataService $recurringDataService
     * @param OrderStateSetter $orderStateSetter
     * @param OrderItemsSyncer $orderItemsSyncer
     * @param OrderShippingMethodSyncer $orderShippingMethodSyncer
     * @param OrderAddressUpdater $orderAddressUpdater
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
        private readonly QuoteManagement $quoteManagement,
        private readonly Payment $paymentManagement,
        private readonly RecurringDataService $recurringDataService,
        private readonly OrderStateSetter $orderStateSetter,
        private readonly OrderItemsSyncer $orderItemsSyncer,
        private readonly OrderShippingMethodSyncer $orderShippingMethodSyncer,
        private readonly OrderAddressUpdater $orderAddressUpdater
    ) {
    }

    /**
     * Place a Magento order in STATE_PENDING_PAYMENT immediately when the checkout page loads.
     *
     * Called from OrderService::getQliroOrder() after QliroOrder::get() has hydrated the quote with the
     * customer's address and email. Saves order_id on the link so the Success controller can
     * find it without polling.
     *
     * @param MagentoQuote $quote  Already-hydrated quote (address + email populated by QliroOrder::get())
     * @param LinkInterface $link  Active link for this quote
     * @return Order
     * @throws TerminalException
     */
    public function placePending(MagentoQuote $quote, LinkInterface $link): Order
    {
        $this->logManager->setMark('PLACE PENDING ORDER');

        try {
            $this->currentQuote = $quote;

            $this->addPaymentMethodToQuote($link);
            $this->ensureBillingAddressEmail($quote);
            $this->prepareQuoteRecurringInfo();
            $this->quoteManagement->recalculateAndSaveQuote($quote);

            $this->logManager->debug('Placing pending order from quote', [
                'extra' => [
                    'quote_id'       => $quote->getId(),
                    'qliro_order_id' => $link->getQliroOrderId(),
                ],
            ]);

            $order = $this->orderPlacer->place($quote);
            $quote->setIsActive(true)->save();
            $orderId = $order->getId();

            $link->setOrderId($orderId);
            $link->setMessage(sprintf('Created pending order %s', $order->getIncrementId()));
            $this->linkRepository->save($link);

            $this->logManager->debug('Pending order placed successfully', [
                'extra' => [
                    'quote_id'        => $quote->getId(),
                    'qliro_order_id'  => $link->getQliroOrderId(),
                    'order_id'        => $orderId,
                    'increment_id'    => $order->getIncrementId(),
                ],
            ]);

            return $order;

        } catch (\Exception $exception) {
            $this->logManager->critical($exception, [
                'extra' => [
                    'quote_id'       => $quote->getId(),
                    'qliro_order_id' => $link->getQliroOrderId(),
                ],
            ]);
            throw new TerminalException($exception->getMessage(), $exception->getCode(), $exception);
        } finally {
            $this->logManager->setMark(null);
        }
    }

    /**
     * Hydrate an existing pending order with confirmed data from the Qliro callback and finalise it.
     *
     * Called from CheckoutStatus::update() when Qliro's server-to-server push arrives.
     * Updates billing/shipping addresses and customer data on the already-placed order,
     * creates the payment transaction, and moves the order to its terminal state.
     *
     * @param Order $order  The pending Magento order created in placePending()
     * @param array $qliroOrder  Raw Qliro order array from the callback
     * @return Order
     * @throws TerminalException
     */
    public function hydrateAndFinalise(Order $order, array $qliroOrder): Order
    {
        $orderId       = $order->getId();
        $qliroOrderId  = $qliroOrder['OrderId'] ?? null;
        $this->logManager->setMark('HYDRATE ORDER');

        try {
            $link = $this->linkRepository->getByOrderId($orderId);

            $this->logManager->debug('Hydrating pending order with Qliro callback data', [
                'extra' => [
                    'order_id'       => $orderId,
                    'qliro_order_id' => $qliroOrderId,
                ],
            ]);

            // Update order addresses with the confirmed values from Qliro
            $this->orderAddressUpdater->update($order, $qliroOrder);

            // Sync confirmed quantities and shipping from Qliro — customer may have changed
            // these inside the iframe after the pending order was placed at page-load time
            $this->orderItemsSyncer->sync($order, $qliroOrder);
            $this->orderShippingMethodSyncer->sync($order, $qliroOrder);

            // Add payment method / shipping additional info from Qliro response
            $this->addPaymentInfoToOrderPayment($order->getPayment(), $link, $qliroOrder['PaymentMethod'] ?? []);
            $this->addShippingInfoToOrderPayment($order->getPayment(), $qliroOrder);

            $this->handlePlacedOrderRecurringInfo(
                $order,
                ($qliroOrder['Customer'] ?? [])['PersonalNumber'] ?? null
            );

            $this->paymentManagement->createPaymentTransaction($order, $qliroOrder, Order::STATE_PENDING_PAYMENT);

            $link->setMessage(sprintf('Hydrated order %s from Qliro callback', $order->getIncrementId()));
            $this->linkRepository->save($link);

            $this->applyQliroOrderStatus($order);

            $this->logManager->debug('Order hydrated and finalised', [
                'extra' => [
                    'order_id'      => $orderId,
                    'qliro_order_id' => $qliroOrderId,
                ],
            ]);

            return $order;

        } catch (\Exception $exception) {
            $this->logManager->critical($exception, [
                'extra' => [
                    'order_id'       => $orderId,
                    'qliro_order_id' => $qliroOrderId,
                ],
            ]);
            throw new TerminalException($exception->getMessage(), $exception->getCode(), $exception);
        } finally {
            $this->logManager->setMark(null);
        }
    }

    /**
     * Apply the Qliro order status stored on the link to the already-placed Magento order.
     * Returns true if a terminal status (Completed/OnHold/Refused) was applied, false if still InProcess.
     *
     * @param Order $order
     * @return bool
     */
    public function applyQliroOrderStatus(Order $order): bool
    {
        $orderId = $order->getId();

        try {
            $link = $this->linkRepository->getByOrderId($orderId);

            switch ($link->getQliroOrderStatus()) {
                case 'Completed':
                    if ($order->getCanSendNewEmailFlag() && !$order->getEmailSent()) {
                        try {
                            $this->orderSender->send($order);
                        } catch (\Exception $exception) {
                            $this->logManager->critical($exception, [
                                'extra' => ['order_id' => $orderId],
                            ]);
                        }
                    }

                    $paymentAdditionalInfo = $order->getPayment()->getAdditionalInformation();
                    if (empty($paymentAdditionalInfo['qliroone_updated_merchant_reference'])) {
                        /** @var AdminUpdateMerchantReferenceRequestInterface $request */
                        $request = $this->payloadConverter->fromArray(
                            [
                                'OrderId'              => $link->getQliroOrderId(),
                                'NewMerchantReference' => $order->getIncrementId(),
                            ],
                            AdminUpdateMerchantReferenceRequestInterface::class
                        );

                        $response       = $this->orderManagementApi->updateMerchantReference($request, $order->getStoreId());
                        $transactionId  = $response && $response->getPaymentTransactionId()
                            ? $response->getPaymentTransactionId()
                            : 'unknown';

                        $this->logManager->debug('Merchant reference updated', [
                            'payment_transaction_id'  => $transactionId,
                            'qliro_order_id'          => $link->getQliroOrderId(),
                            'order_id'                => $orderId,
                            'new_merchant_reference'  => $order->getIncrementId(),
                        ]);

                        $paymentAdditionalInfo['qliroone_updated_merchant_reference'] = true;
                        $order->getPayment()->setAdditionalInformation($paymentAdditionalInfo);
                    }

                    $this->orderStateSetter->apply($order, Order::STATE_NEW);
                    return true;

                case 'OnHold':
                    $this->orderStateSetter->apply($order, Order::STATE_PAYMENT_REVIEW);
                    return true;

                case 'Refused':
                    $link->setIsActive(false);
                    $link->setMessage(sprintf('Order #%s marked as canceled', $order->getIncrementId()));
                    $this->linkRepository->save($link);

                    $order->addCommentToStatusHistory(
                        'Customer payment was refused by Qliro. Proceeding with order cancellation.'
                    )->setIsCustomerNotified(false);

                    $order->getPayment()->setNotificationResult(true);
                    $order->getPayment()->deny(false);
                    $this->orderRepository->save($order);
                    return true;

                case 'InProcess':
                default:
                    $this->logManager->debug('Qliro order status not yet terminal', [
                        'extra' => [
                            'order_id'           => $orderId,
                            'qliro_order_id'     => $link->getQliroOrderId(),
                            'qliro_order_status' => $link->getQliroOrderStatus(),
                        ],
                    ]);
                    return false;
            }

        } catch (\Exception $exception) {
            $this->logManager->critical($exception, [
                'extra' => ['order_id' => $orderId],
            ]);
            return false;
        }
    }

    /**
     * Ensure the billing address has an email so Magento's SubmitQuoteValidator passes.
     *
     * At OrderService::getQliroOrder() time the customer has not yet filled in the Qliro iframe,
     * so the Qliro order has no customer data and QuoteFromOrderConverter is a no-op.
     * Without an email on the billing address, OrderPlacer::prepareGuestQuote() sets
     * $quote->setCustomerEmail('') and SubmitQuoteValidator throws "Email has a wrong format".
     *
     * We use the store's general contact email as a placeholder. hydrateAndFinalise()
     * will overwrite it with the customer's real email once the CheckoutStatus callback
     * arrives with the confirmed Qliro order data.
     *
     * For logged-in customers the email is already present on the quote, so this is a no-op.
     *
     * @param MagentoQuote $quote
     */
    private function ensureBillingAddressEmail(MagentoQuote $quote): void
    {
        $billing = $quote->getBillingAddress();

        if ($billing->getEmail()) {
            return; // already set — customer is logged in or iframe already filled
        }

        // Fallback 1: quote-level customer email (logged-in customer)
        if ($quote->getCustomerEmail()) {
            $billing->setEmail($quote->getCustomerEmail());
            return;
        }

        // Fallback 2: store general contact email as a placeholder for guests.
        // This is overwritten with the real customer email in hydrateAndFinalise().
        $storeEmail = $quote->getStore()->getConfig('trans_email/ident_general/email');
        if ($storeEmail) {
            $billing->setEmail($storeEmail);
            return;
        }

        // Last resort: a syntactically valid placeholder that will always pass validation.
        // hydrateAndFinalise() will replace this before the order confirmation email is sent.
        $billing->setEmail('pending@qliro.placeholder');
    }

    /**
     * Set only the payment method code on the quote. Address and customer data are already
     * present (populated by QliroOrder::get() → QuoteFromOrderConverter::convert() earlier).
     *
     * @param LinkInterface $link
     */
    private function addPaymentMethodToQuote(LinkInterface $link): void
    {
        $payment = $this->currentQuote->getPayment();
        $payment->setAdditionalInformation(Config::QLIROONE_ADDITIONAL_INFO_QLIRO_ORDER_ID, $link->getQliroOrderId());
        $payment->setAdditionalInformation(Config::QLIROONE_ADDITIONAL_INFO_REFERENCE, $link->getReference());
    }

    /**
     * Store payment-related data on the order payment (called during hydrateAndFinalise).
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param LinkInterface $link
     * @param array $paymentMethod
     */
    private function addPaymentInfoToOrderPayment(
        \Magento\Payment\Model\InfoInterface $payment,
        LinkInterface $link,
        array $paymentMethod
    ): void {
        $payment->setAdditionalInformation(Config::QLIROONE_ADDITIONAL_INFO_QLIRO_ORDER_ID, $link->getQliroOrderId());
        $payment->setAdditionalInformation(Config::QLIROONE_ADDITIONAL_INFO_REFERENCE, $link->getReference());

        if ($paymentMethod) {
            $payment->setAdditionalInformation(
                Config::QLIROONE_ADDITIONAL_INFO_PAYMENT_METHOD_CODE,
                $paymentMethod['PaymentTypeCode'] ?? null
            );
            $payment->setAdditionalInformation(
                Config::QLIROONE_ADDITIONAL_INFO_PAYMENT_METHOD_NAME,
                $paymentMethod['PaymentMethodName'] ?? null
            );
        }
    }

    /**
     * Store shipping properties from Qliro order items metadata on the order payment.
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param array $order
     */
    private function addShippingInfoToOrderPayment(
        \Magento\Payment\Model\InfoInterface $payment,
        array $order
    ): void {
        foreach ($order['OrderItems'] ?? [] as $orderItem) {
            $metadata                     = $orderItem['Metadata'] ?? [];
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

    /**
     * Schedule next recurring order date if applicable.
     */
    private function prepareQuoteRecurringInfo(): void
    {
        if (!$this->qliroConfig->isUseRecurring()) {
            return;
        }
        $recurringInfo = $this->recurringDataService->quoteGetter($this->currentQuote);
        if ($recurringInfo->getEnabled()) {
            $this->recurringDataService->scheduleNextRecurringOrder($this->currentQuote);
        }
    }

    /**
     * Persist recurring order info against the placed order if applicable.
     *
     * @param Order $order
     * @param string|null $personalNumber
     */
    private function handlePlacedOrderRecurringInfo(Order $order, ?string $personalNumber): void
    {
        if (!$this->qliroConfig->isUseRecurring()) {
            return;
        }
        $recurringInfo = $this->recurringDataService->orderGetter($order);
        if ($recurringInfo->getEnabled()) {
            $this->recurringDataService->saveNewOrderRecurringInfo($order, $personalNumber);
        }
    }
}
