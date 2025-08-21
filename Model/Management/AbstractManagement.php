<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Management;

use Magento\Quote\Api\Data\CartInterface;

/**
 * QliroOne management class
 */
abstract class AbstractManagement
{
    const QLIRO_SKIP_ACTUAL_CAPTURE = 'qliro_skip_actual_capture';

    // CheckoutStatus can only create an order, if POLL was unsuccessful for 1 minute
    const QLIRO_POLL_VS_CHECKOUT_STATUS_TIMEOUT = 60;

    // If placed_at fails, it will still attempt to reply to checkout status, 3 minutes after customer has opened checkout
    const QLIRO_POLL_VS_CHECKOUT_STATUS_TIMEOUT_FINAL = 180;

    /**
     * @var \Magento\Quote\Model\Quote
     */
    private $quote;

    /**
     * Set quote in the Management class
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return AbstractManagement
     */
    public function setQuote($quote)
    {
        $this->quote = $quote;

        return $this;
    }

    /**
     * Get quote from the Management class
     *
     * @return \Magento\Quote\Model\Quote
     */
    public function getQuote()
    {
        if (!($this->quote instanceof CartInterface)) {
            throw new \LogicException('Quote must be set before it is fetched.');
        }

        return $this->quote;
    }
}
