<?php declare(strict_types=1);
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder;

use Magento\Quote\Api\Data\CartInterface;
use Qliro\QliroOne\Api\HashResolverInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\QliroOrder\HashResolver\IncrementIdHashResolver;
use Qliro\QliroOne\Model\QliroOrder\HashResolver\RandomHashResolver;

/**
 * Resolves a reference hash for a given cart instance.
 * Determines the strategy for generating the hash based on the configuration.
 */
class ReferenceHashResolver implements HashResolverInterface
{
    /**
     * @param Config $qliroConfig
     * @param RandomHashResolver $randomResolver
     * @param IncrementIdHashResolver $incrementIdResolver
     */
    public function __construct(
        private readonly Config $qliroConfig,
        private readonly RandomHashResolver $randomResolver,
        private readonly IncrementIdHashResolver $incrementIdResolver
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolveHash(CartInterface $quote): string
    {
        $storeId = $quote->getStoreId() !== null ? (int) $quote->getStoreId() : null;

        return $this->qliroConfig->isUseIncrementIdAsReference($storeId)
            ? $this->incrementIdResolver->resolveHash($quote)
            : $this->randomResolver->resolveHash($quote);
    }
}
