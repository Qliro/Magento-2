<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Api\Data\CartInterface;

/**
 * Hash Resolver interface
 */
interface HashResolverInterface
{
    /**
     * Regular expression pattern to validate a merchant reference.
     * The reference can include alphanumeric characters, underscores,
     * and hyphens, with a length of 1 to 25 characters.
     */
    public const VALIDATE_MERCHANT_REFERENCE = '/^[A-Za-z0-9_-]{1,25}$/';

    /**
     * Resolves and retrieves a hash string for the given cart instance.
     *
     * @param CartInterface $quote The cart instance for which the hash is to be resolved.
     * @throws LocalizedException
     * @return string The resolved hash string associated with the provided cart.
     */
    public function resolveHash(CartInterface $quote): string;
}
