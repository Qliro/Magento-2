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
     * @inerhitDoc
     */
    public function getExpirationTimestamp(): int
    {
        return strtotime('+2 hour');
    }

    /**
     * @inerhitDoc
     */
    public function getAdditionalData(): ?string
    {
        return $this->quote instanceof Quote ? (string)$this->quote->getId() : null;
    }
}
