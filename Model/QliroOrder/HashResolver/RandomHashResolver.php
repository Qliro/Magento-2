<?php declare(strict_types=1);
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\HashResolver;

use Magento\Quote\Api\Data\CartInterface;
use Qliro\QliroOne\Api\HashResolverInterface;

/**
 * Class responsible for generating a random hash for a given cart instance.
 * Implements the HashResolverInterface.
 *
 * The hash is composed of characters from a predefined character set and is
 * generated to meet the maximum length specified by the interface.
 */
class RandomHashResolver implements HashResolverInterface
{
    /**
     * Defines a character set containing digits, lowercase, and uppercase letters.
     */
    private const CHARSET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /**
     * Defines the maximum length for a hash value.
     */
    private const HASH_MAX_LENGTH = 25;

    /**
     * @inheritDoc
     */
    public function resolveHash(CartInterface $quote): string
    {
        $charset = self::CHARSET;
        $maxIndex = strlen($charset) - 1;
        $hash = '';
        for ($i = 0; $i < self::HASH_MAX_LENGTH; $i++) {
            $hash .= $charset[random_int(0, $maxIndex)];
        }

        return $hash;
    }
}

