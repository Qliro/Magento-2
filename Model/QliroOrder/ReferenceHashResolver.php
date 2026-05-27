<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

// @codingStandardsIgnoreFile
// phpcs:ignoreFile

namespace Qliro\QliroOne\Model\QliroOrder;

use Magento\Quote\Api\Data\CartInterface;
use Qliro\QliroOne\Api\HashResolverInterface;

/**
 * QliroOne order reference hash resolver class
 */
class ReferenceHashResolver implements HashResolverInterface
{
    const string CHARSET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Resolve a supposedly unique hash for QliroOne order reference.
     * It must be a string of any length, but important to remember that it will be truncated to up to 25 characters max
     *
     * @param CartInterface $quote
     * @return string
     */
    public function resolveHash(CartInterface $quote): string
    {
        srand();
        $result = '';
        for ($index = 0; $index < self::HASH_MAX_LENGTH; ++$index) {
            $result .= self::CHARSET[rand(0, strlen(self::CHARSET) - 1)];
        }

        return $result;
    }
}
