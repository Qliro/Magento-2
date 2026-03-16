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
     * Class constructor
     *
     * @param SuccessSession $checkoutSession
     */
    public function __construct(
        private readonly SuccessSession $checkoutSession
    ) {
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
