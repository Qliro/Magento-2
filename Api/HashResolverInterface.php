<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api;

use Magento\Quote\Api\Data\CartInterface;

/**
 * Hash Resolver interface
 *
 * @api
 */
interface HashResolverInterface
{
    const int HASH_MAX_LENGTH = 25;

    /**
     * A merchant reference must match this pattern to be accepted by Qliro.
     */
    const string VALIDATE_MERCHANT_REFERENCE = '/^[A-Za-z0-9_-]{1,25}$/';

    /**
     * Resolve a unique hash for a QliroOne order reference (truncated to 25 characters max)
     *
     * @param CartInterface $quote
     * @return string
     */
    public function resolveHash(CartInterface $quote): string;
}
