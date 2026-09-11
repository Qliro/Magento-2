<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Security;

use Magento\Quote\Model\Quote;

/**
 * AJAX Token handling class
 */
class AjaxToken extends CallbackToken
{
    /**
     * @var Quote
     */
    private $quote;

    /**
     * Set quote to properly calculate the token
     *
     * @param Quote $quote
     * @return AjaxToken
     */
    public function setQuote($quote) : self
    {
        $this->quote = $quote;
        return $this;
    }

    /**
     * The checkout token lives as long as a checkout does, not as long as a callback url
     *
     * @inerhitDoc
     */
    protected function getLifetimeSeconds(): int
    {
        return 2 * 3600;
    }

    /**
     * A checkout tab left open past two hours is a customer, not a misconfiguration
     *
     * @inerhitDoc
     */
    protected function getExpiryLogLevel(): string
    {
        return 'debug';
    }

    /**
     * @inerhitDoc
     */
    protected function getExpiryMessage(): string
    {
        return 'checkout token expired {expired} seconds ago, the request was refused';
    }

    /**
     * @inerhitDoc
     */
    protected function describeLifetime(): string
    {
        return '2 hours, the fixed lifetime of a checkout token';
    }

    /**
     * @inerhitDoc
     */
    public function getAdditionalData(): ?string
    {
        return $this->quote instanceof Quote ? (string)$this->quote->getId() : null;
    }
}
