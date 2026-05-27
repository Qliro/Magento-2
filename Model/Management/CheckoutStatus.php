<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromOrderConverter;
use Qliro\QliroOne\Model\ResourceModel\Lock;

/**
 * Processes Qliro CheckoutStatus push callbacks.
 *
 * Order creation now happens earlier — in OrderService::getQliroOrder() when the checkout page loads.
 * By the time this callback arrives the Magento order already exists (STATE_PENDING_PAYMENT).
 *
 * This handler's job is to:
 *  1. Fetch the full confirmed Qliro order.
 *  2. Hydrate the pending Magento order with the confirmed address + payment data.
 *  3. Move the order to its terminal state (New / PaymentReview / Cancelled).
 *
 * Fallback: if the pending order was never created (e.g. browser crash during page load),
 * the original late-placement behaviour is preserved so no payment is ever lost.
 */
class CheckoutStatus
{
    /**
     * Class constructor
     *
     * @param MerchantInterface $merchantApi
     * @param LinkRepositoryInterface $linkRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param CartRepositoryInterface $quoteRepository
     * @param LogManager $logManager
     * @param PlaceOrder $placeOrder
     * @param QliroOrder $qliroOrder
     * @param QuoteFromOrderConverter $quoteFromOrderConverter
     * @param Quote $quoteManagement
     * @param Lock $lock
     */
    public function __construct(
        private readonly MerchantInterface $merchantApi,
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly LogManager $logManager,
        private readonly PlaceOrder $placeOrder,
        private readonly QliroOrder $qliroOrder,
        private readonly QuoteFromOrderConverter $quoteFromOrderConverter,
        private readonly Quote $quoteManagement,
        private readonly Lock $lock
    ) {
    }

    /**
     * Handle a CheckoutStatus push from Qliro.
     *
     * @param array $checkoutStatus
     * @return array
     */
    public function update(array $checkoutStatus): array
    {
        $qliroOrderId = $checkoutStatus['OrderId'] ?? null;
        $logContext   = ['extra' => ['qliro_order_id' => $qliroOrderId]];

        try {
            try {
                $link = $this->linkRepository->getByQliroOrderId($qliroOrderId);
            } catch (NoSuchEntityException $exception) {
                $this->handleOrderCancellationIfRequired($checkoutStatus);
                return ['CallbackResponse' => 'Received', 'callbackResponseCode' => 200];
            }

            $this->logManager->setMerchantReference($link->getReference());

            $link->setQliroOrderStatus($checkoutStatus['Status'] ?? '');
            $this->linkRepository->save($link);

            $qliroOrder = $this->merchantApi->getOrder($qliroOrderId);

            // Lock the entire order-load → hydrate block so concurrent callbacks
            // (original delivery + retry) cannot race on the same order.
            if (!$this->lock->lock($qliroOrderId)) {
                $this->logManager->warning(
                    'CheckoutStatus: could not acquire lock — concurrent request in progress.',
                    ['extra' => ['qliro_order_id' => $qliroOrderId]]
                );
                // Return a pending response so Qliro retries once the concurrent process finishes,
                // rather than an error response which Qliro treats the same way but is misleading.
                return ['CallbackResponse' => 'Order creation pending', 'callbackResponseCode' => 200];
            }

            try {
                // Re-fetch link under the lock so we see the latest order_id even if a
                // concurrent early-placement just wrote it.
                $link = $this->linkRepository->getByQliroOrderId($qliroOrderId);
                $orderId = $link->getOrderId();

                if (empty($orderId)) {
                    $this->logManager->warning(
                        'CheckoutStatus: no pending order found — falling back to late order creation.',
                        ['extra' => ['qliro_order_id' => $qliroOrderId, 'link_id' => $link->getId()]]
                    );

                    $quote = $this->quoteRepository->get($link->getQuoteId());

                    $this->quoteFromOrderConverter->convert($qliroOrder, $quote);
                    $this->quoteManagement->recalculateAndSaveQuote($quote);

                    $order = $this->placeOrder->placePending($quote, $link);
                } else {
                    $order = $this->orderRepository->get($orderId);
                }

                $this->placeOrder->hydrateAndFinalise($order, $qliroOrder);
            } finally {
                $this->lock->unlock($qliroOrderId);
            }

            if (in_array($checkoutStatus['Status'] ?? '', ['Completed', 'OnHold'], true)) {
                try {
                    $quote = $this->quoteRepository->get($link->getQuoteId());
                    if ($quote->getIsActive()) {
                        $quote->setIsActive(false);
                        $this->quoteRepository->save($quote);
                    }
                } catch (\Exception $e) {
                    $this->logManager->warning(
                        'CheckoutStatus: failed to deactivate quote after terminal status.',
                        ['extra' => ['quote_id' => $link->getQuoteId(), 'error' => $e->getMessage()]]
                    );
                }
            }

            return ['CallbackResponse' => 'Received', 'callbackResponseCode' => 200];

        } catch (\Exception $exception) {
            $this->logManager->critical($exception, $logContext);
            return ['CallbackResponse' => 'OrderNotFound', 'callbackResponseCode' => 500];
        }
    }

    /**
     * When a Completed push arrives for an order whose active link is gone, cancel the Qliro
     * order to avoid charging the customer for an order that was never created in Magento.
     *
     * @param array $checkoutStatus
     */
    private function handleOrderCancellationIfRequired(array $checkoutStatus): void
    {
        if (($checkoutStatus['Status'] ?? null) !== 'Completed') {
            return;
        }

        try {
            $link = $this->linkRepository->getByQliroOrderId($checkoutStatus['OrderId'] ?? null, false);

            // If a Magento order is already linked, do not cancel the Qliro order.
            // Cancelling here would void a real paid order in Qliro while leaving
            // the Magento order alive — the exact scenario that caused orphaned orders.
            if ($link->getOrderId()) {
                $this->logManager->warning(
                    'CheckoutStatus: skipping cancel — a Magento order already exists for this Qliro order.',
                    ['extra' => [
                        'qliro_order_id' => $checkoutStatus['OrderId'] ?? null,
                        'order_id'       => $link->getOrderId(),
                    ]]
                );
                return;
            }

            $this->logManager->setMerchantReference($link->getReference());
            $link->setQliroOrderStatus($checkoutStatus['Status'] ?? '');

            try {
                $this->qliroOrder->cancel($link->getQliroOrderId());
                $link->setMessage(sprintf('Requested to cancel QliroOne order #%s', $link->getQliroOrderId()));
            } catch (TerminalException $exception) {
                $message = sprintf('Failed to cancel QliroOne order #%s', $link->getQliroOrderId());
                $this->logManager->critical($message, ['exception' => $exception]);
                $link->setMessage($message);
            }

            $this->linkRepository->save($link);
        } catch (\Exception $exception) {
            $this->logManager->critical($exception);
        }
    }

}
