<?php declare(strict_types=1);
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

// @codingStandardsIgnoreFile
// phpcs:ignoreFile

namespace Qliro\QliroOne\Model\QliroOrder;

use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\HashResolverInterface;
use Qliro\QliroOne\Model\Config;
use Throwable;

/**
 * QliroOne order reference hash resolver class
 */
class ReferenceHashResolver implements HashResolverInterface
{
    const CHARSET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    public function __construct(
        private readonly Config $qliroConfig
    ) {
    }

    /**
     * Resolve a supposedly unique hash for QliroOne order reference.
     * It must be a string of any length, but important to remember that it will be truncated to up to 25 characters max.
     *
     * When the admin setting "Use Magento Increment ID as a reference" is enabled,
     * the reserved increment ID from the quote is returned instead of a random hash.
     * This lets merchant settlements (e.g. PayPal) be matched to Magento orders
     * using their human-readable order number.
     *
     * @param CartInterface $quote The quote object for which the hash reference is being resolved.
     * @return string The resolved hash, which could either be an increment ID or a randomly generated hash.
     */
    public function resolveHash(CartInterface $quote): string
    {
        $storeId = $quote->getStoreId() !== null ? (int) $quote->getStoreId() : null;

        if ($this->qliroConfig->isUseIncrementIdAsReference($storeId)) {
            $incrementId = $this->resolveQuoteIncrementId($quote);

            if (!empty($incrementId)) {
                return $incrementId;
            }
            // Fall through to random hash if we could not reserve an increment ID
            // for any reason — we must still return a valid reference.
        }

        return $this->generateRandomHash();
    }

    /**
     * Generates a random hash string based on a predefined character set.
     *
     * @return string A randomly generated hash.
     */
    private function generateRandomHash(): string
    {
        $charset = self::CHARSET;
        $max = strlen($charset) - 1;
        $result = '';
        for ($index = 0; $index < self::HASH_MAX_LENGTH; ++$index) {
            $result .= self::CHARSET[rand(0, strlen(self::CHARSET) - 1)];
        }

        return $result;
    }

    /**
     * Resolves the increment ID for a given quote. If no increment ID is set, it attempts to reserve a new one.
     *
     * @param CartInterface $quote The quote object for which the increment ID is being resolved.
     * @return string|null The resolved increment ID, or null if unable to resolve.
     */
    private function resolveQuoteIncrementId(CartInterface $quote): ?string
    {
        try {
            $incrementId = $quote->getReservedOrderId();

            if (empty($incrementId) && $quote instanceof Quote) {
                $quote->reserveOrderId();
                $incrementId = $quote->getReservedOrderId();
            }

            return !empty($incrementId) ? (string) $incrementId : null;
        } catch (Throwable $e) {
            return null;
        }
    }
}
