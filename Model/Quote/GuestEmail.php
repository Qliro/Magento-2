<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Quote;

use Magento\Framework\Validator\EmailAddress;
use Magento\Quote\Model\Quote;

/**
 * Put the email a guest typed in the native checkout on the quote a new Qliro order is built from.
 *
 * Core stores it only with set-payment-information, sent alongside the snippet fetch (PLIN-419).
 * Kept on the quote object only, under a key no table has a column for: touching an address would
 * have the snippet's quote save write its stale copy over the one set-payment-information saved.
 */
class GuestEmail
{
    public const QUOTE_KEY = 'qliro_guest_email';

    /**
     * @param EmailAddress $emailValidator the one core validates a quote address email with
     */
    public function __construct(
        private readonly EmailAddress $emailValidator
    ) {
    }

    /**
     * @param Quote $quote
     * @param string $email
     * @return bool whether the email was applied
     */
    public function apply(Quote $quote, string $email): bool
    {
        $email = trim($email);

        if ($email === '' || $quote->getCustomerId() || !$this->emailValidator->isValid($email)) {
            return false;
        }

        // An email the quote already holds is what core stored, and it wins
        $shippingAddress = $quote->getShippingAddress();

        if ($shippingAddress && $shippingAddress->getEmail()) {
            return false;
        }

        $billingAddress = $quote->getBillingAddress();

        if ($billingAddress && $billingAddress->getEmail()) {
            return false;
        }

        $quote->setData(self::QUOTE_KEY, $email);

        return true;
    }
}
