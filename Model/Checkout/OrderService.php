<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Checkout;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\Checkout\OrderServiceInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\CheckoutStatus as CheckoutStatusManagement;
use Qliro\QliroOne\Model\Management\PlaceOrder;
use Qliro\QliroOne\Model\Management\QliroOrder as QliroOrderManagement;
use Qliro\QliroOne\Model\Management\Quote as QuoteManagement;
use Qliro\QliroOne\Model\Management\ShippingMethod as ShippingMethodManagement;
use Qliro\QliroOne\Model\Quote\Agent;

/**
 * Checkout lifecycle service
 *
 */
class OrderService implements OrderServiceInterface
{
    /**
     * Prefix for the per-quote serialization lock. The quote id is appended.
     */
    private const string LOCK_PREFIX = 'qliroone_quote_update_';

    /**
     * Seconds to wait for the per-quote lock before proceeding without it.
     * A quick shipping/quote update finishes well within this window, so concurrent
     * requests queue instead of racing; the timeout is only a deadlock backstop.
     */
    private const LOCK_TIMEOUT = 10;

    /**
     * Class constructor
     *
     * @param CheckoutSession                     $checkoutSession
     * @param QliroOrderManagement                $qliroOrderManagement
     * @param CheckoutStatusManagement            $checkoutStatusManagement
     * @param ShippingMethodManagement            $shippingMethodManagement
     * @param QuoteManagement                     $quoteManagement
     * @param LinkRepositoryInterface             $linkRepository
     * @param Agent                               $quoteAgent
     * @param LogManager                          $logManager
     * @param PlaceOrder                          $placeOrder
     * @param LockManagerInterface                $lockManager
     */
    public function __construct(
        private readonly CheckoutSession          $checkoutSession,
        private readonly QliroOrderManagement     $qliroOrderManagement,
        private readonly CheckoutStatusManagement $checkoutStatusManagement,
        private readonly ShippingMethodManagement $shippingMethodManagement,
        private readonly QuoteManagement          $quoteManagement,
        private readonly LinkRepositoryInterface  $linkRepository,
        private readonly Agent                    $quoteAgent,
        private readonly LogManager               $logManager,
        private readonly PlaceOrder               $placeOrder,
        private readonly LockManagerInterface     $lockManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getQliroOrder(bool $allowRecreate = true): array
    {
        $quote = $this->getQuote();

        // Ensure PHP keeps running even if the browser cancels the page load (e.g. pressing Escape).
        // Without this, PHP can abort between creating the Qliro order and saving the Magento
        // pending order + link.order_id, leaving an orphaned Qliro order with no Magento counterpart.
        $previousAbortSetting = (bool) ignore_user_abort(true);

        try {
            try {
                $this->linkRepository->unlock((int)$quote->getId());
            } catch (NoSuchEntityException $e) {
                // No link yet — nothing to unlock
            }

            $qliroOrder = $this->qliroOrderManagement->get($quote, $allowRecreate);

            try {
                $link = $this->linkRepository->getByQuoteId((int) $quote->getId());
                if ($link->getQliroOrderId() && !$link->getOrderId()) {
                    $this->placeOrder->placePending($quote, $link);
                }
            } catch (\Exception $e) {
                $this->logManager->warning(
                    'Early order placement failed, will fall back to late placement: ' . $e->getMessage(),
                    ['extra' => ['quote_id' => $quote->getId()]]
                );
            }

            $this->quoteAgent->store($quote);

            return $qliroOrder;

        } catch (\Exception $exception) {
            $this->logManager->critical(
                sprintf('QliroOne Checkout has failed to load. %s', $exception->getMessage()),
                ['exception' => $exception, 'extra' => $exception->getTrace()]
            );

            return [];
        } finally {
            ignore_user_abort($previousAbortSetting);
        }
    }

    /**
     * @inheritDoc
     */
    public function checkoutStatus(array $checkoutStatus): array
    {
        return $this->checkoutStatusManagement->update($checkoutStatus);
    }

    /**
     * @inheritDoc
     */
    public function getShippingMethods(array $updateContainer): array
    {
        return $this->shippingMethodManagement->get($updateContainer);
    }

    /**
     * @inheritDoc
     */
    public function validateQliroOrder(array $validateContainer): array
    {
        return $this->qliroOrderManagement->validate($validateContainer);
    }

    /**
     * @inheritDoc
     */
    public function updateCustomer(array $customerData): void
    {
        $this->withQuoteLock(function () use ($customerData) {
            $this->quoteManagement->updateCustomer($this->getQuote(), $customerData);
            return null;
        });
    }

    /**
     * @inheritDoc
     */
    public function updateShippingMethod(string $code, ?string $secondaryOption = null, ?float $price = null): bool
    {
        return (bool) $this->withQuoteLock(
            fn () => $this->shippingMethodManagement->update($this->getQuote(), $code, $secondaryOption, $price)
        );
    }

    /**
     * @inheritDoc
     */
    public function updateShippingPrice(?float $price): bool
    {
        return (bool) $this->withQuoteLock(
            fn () => $this->quoteManagement->updateShippingPrice($this->getQuote(), $price)
        );
    }

    /**
     * @inheritDoc
     */
    public function updateFee(float $fee): bool
    {
        return (bool) $this->withQuoteLock(
            fn () => $this->quoteManagement->updateFee($this->getQuote(), $fee)
        );
    }

    /**
     * @inheritDoc
     */
    public function pushQuoteUpdate(): void
    {
        $this->withQuoteLock(function () {
            $this->quoteManagement->updateQliroOrder($this->getQuote());
            return null;
        });
    }

    /**
     *
     * @template T
     * @param callable():T $operation
     * @return T|null
     */
    private function withQuoteLock(callable $operation): mixed
    {
        $quoteId = (int) $this->getQuote()->getId();
        if ($quoteId <= 0) {
            return $operation();
        }

        $lockName = self::LOCK_PREFIX . $quoteId;
        $locked = false;

        try {
            $locked = $this->lockManager->lock($lockName, self::LOCK_TIMEOUT);
            if (!$locked) {
                $this->logManager->warning(
                    'Could not acquire per-quote lock for a quote update within the timeout; '
                    . 'proceeding without it.',
                    ['extra' => ['quote_id' => $quoteId]]
                );
            }

            return $operation();
        } finally {
            if ($locked) {
                try {
                    $this->lockManager->unlock($lockName);
                } catch (\Exception $e) {
                    $this->logManager->debug(
                        'Failed to release per-quote lock: ' . $e->getMessage(),
                        ['extra' => ['quote_id' => $quoteId]]
                    );
                }
            }
        }
    }

    /**
     * Return the current session quote
     */
    private function getQuote(): Quote
    {
        return $this->checkoutSession->getQuote();
    }

}
