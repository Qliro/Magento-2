<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

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
        $this->checkoutSession->setSuccessHtmlSnippet($snippet);
        $this->checkoutSession->setSuccessIncrementId($order->getIncrementId());
        $this->checkoutSession->setSuccessOrderId($order->getId());
        $this->checkoutSession->setSuccessHasDisplayed(false);

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
     * Mark success as being displayed, thus not triggering GTM etc if success page is reloaded
     */
    public function setSuccessDisplayed()
    {
        $this->checkoutSession->setSuccessHasDisplayed(true);
    }
}
