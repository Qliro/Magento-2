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
     * A second render covers the one the buyer never saw, and a page that can never report, its
     * request blocked, cannot repeat the purchase on every reload
     */
    public const MAX_SUCCESS_EVENTS = 2;

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
     * Claim the next success event for the order: 1 is the first, 2 the repeat a render gets while
     * the page has not reported it loaded, 0 means none is left
     *
     * @return int
     */
    public function claimSuccessEvent()
    {
        $count = (int)$this->checkoutSession->getSuccessEventCount();

        if ($this->hasSuccessDisplayed() || $count >= self::MAX_SUCCESS_EVENTS) {
            return 0;
        }

        $this->checkoutSession->setSuccessEventCount(++$count);

        return $count;
    }

    /**
     * Mark success as shown in the buyer's browser, so a reload does not count a second purchase
     */
    public function setSuccessDisplayed()
    {
        $this->checkoutSession->setSuccessHasDisplayed(true);
    }
}
