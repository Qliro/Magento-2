<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Security;

use Magento\Quote\Model\Quote;

/**
 * AJAX Token handling class
 */
class AjaxToken extends CallbackToken
{
    /**
     * @var Quote|null
     */
    private ?Quote $quote = null;

    /**
     * Set quote to properly calculate the token
     *
     * @param Quote $quote
     * @return static
     */
    public function setQuote(Quote $quote): static
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
