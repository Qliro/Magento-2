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

    /**
     * Set on the ORDER once this module has sent a capture for it in the current request, so the
     * sibling capture path stands down. capture_on_shipment and capture_on_invoice are independent
     * settings, and with both on one admin action fires both paths: the first ship succeeds and
     * takes the money, the second is refused as already shipped, and the refusal rolls the whole
     * action back, leaving money captured at Qliro and no invoice in Magento (PLIN-381).
     */
    const QLIRO_CAPTURE_SUBMITTED = 'qliro_capture_submitted';

    /**
     * Qliro's answer when the reservation has nothing left to ship, i.e. this capture already
     * happened. Not a failure for us: it means the money moved, so the Magento document must be
     * allowed to complete rather than rolled back (PLIN-381).
     */
    const QLIRO_ERROR_NO_ITEMS_LEFT = 'NO_ITEMS_LEFT_IN_RESERVATION';

    /**
     * Qliro does not know the order id stored for this Magento order under the configured merchant
     * API key. Retrying cannot help; usually the order was created against the other Qliro
     * environment (test against production or the reverse) (PLIN-381).
     */
    const QLIRO_ERROR_ORDER_NOT_FOUND = 'ORDER_NOT_FOUND';

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
