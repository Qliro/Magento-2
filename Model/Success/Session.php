<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Success;

use Magento\Checkout\Model\Session as SuccessSession;

/**
 * Saves information for displaying in success page
 */
class Session
{
    /**
     * @var SuccessSession
     */
    private $checkoutSession;

    public function __construct(
        SuccessSession $checkoutSession
    ) {
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * @param string $snippet
     * @param \Magento\Sales\Model\Order $order
     */
    public function save($snippet, $order)
    {
        // A second poll for the same order, from another tab, must not bring the purchase back
        if ((int)$this->checkoutSession->getSuccessOrderId() !== (int)$order->getId()) {
            $this->checkoutSession->setSuccessHasDisplayed(false);
            $this->checkoutSession->setSuccessEventCount(0);
        }

        $this->checkoutSession->setSuccessHtmlSnippet($snippet);
        $this->checkoutSession->setSuccessIncrementId($order->getIncrementId());
        $this->checkoutSession->setSuccessOrderId($order->getId());

        /*
         * The keys Magento's own checkout leaves behind, in the same meaning it gives them, since
         * that is what tracking extensions read to identify the order. Written here rather than
         * where the order is placed: placement can happen in the checkoutStatus callback, which
         * has no customer session, while this runs in the buyer's browser for both flows.
         */
        $this->checkoutSession
            ->setLastQuoteId($order->getQuoteId())
            ->setLastSuccessQuoteId($order->getQuoteId())
            ->setLastOrderId($order->getId())
            ->setLastRealOrderId($order->getIncrementId())
            ->setLastOrderStatus($order->getStatus());
    }

    /**
     * Clears saves success
     */
    public function clear()
    {
        $this->checkoutSession->unsSuccessHtmlSnippet();
        $this->checkoutSession->unsSuccessIncrementId();
        $this->checkoutSession->unsSuccessOrderId();
        $this->checkoutSession->unsSuccessHasDisplayed();
        $this->checkoutSession->unsSuccessEventCount();
    }

    /**
     * @return string|null
     */
    public function getSuccessHtmlSnippet()
    {
        return $this->checkoutSession->getSuccessHtmlSnippet();
    }

    /**
     * @return string|null
     */
    public function getSuccessIncrementId()
    {
        return $this->checkoutSession->getSuccessIncrementId();
    }

    /**
     * @return int|null
     */
    public function getSuccessOrderId()
    {
        return $this->checkoutSession->getSuccessOrderId();
    }

    /**
     * @return bool
     */
    public function hasSuccessDisplayed()
    {
        return (bool)$this->checkoutSession->getSuccessHasDisplayed();
    }

    /**
     * Claim the next success event for the order: 1 is the first, every one after it a repeat a
     * render gets while the page has not reported it loaded, 0 once it has
     *
     * Not capped: a render the buyer never saw can follow another, two discarded tabs on the way
     * back from the bank, and the page they do see still needs the purchase. GA4 deduplicates the
     * repeats by transaction id, other tracking reads `repeat`, see README.
     *
     * @return int
     */
    public function claimSuccessEvent()
    {
        if ($this->hasSuccessDisplayed()) {
            return 0;
        }

        $count = (int)$this->checkoutSession->getSuccessEventCount() + 1;
        $this->checkoutSession->setSuccessEventCount($count);

        return $count;
    }

    /**
     * Give back an event whose dispatch failed, so the next render dispatches it again
     */
    public function releaseSuccessEvent()
    {
        $count = (int)$this->checkoutSession->getSuccessEventCount();

        if ($count > 0) {
            $this->checkoutSession->setSuccessEventCount($count - 1);
        }
    }

    /**
     * Mark success as shown in the buyer's browser, so a reload does not count a second purchase
     */
    public function setSuccessDisplayed()
    {
        $this->checkoutSession->setSuccessHasDisplayed(true);
    }
}
