<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Product\Type;

use Qliro\QliroOne\Api\Product\TypeSourceProviderInterface;
use Qliro\QliroOne\Model\Product\ProductPool;

/**
 * Item product type resolver class
 */
class TypeResolver
{
    /**
     * Class constructor
     *
     * @param \Qliro\QliroOne\Model\Product\ProductPool $productPool
     */
    public function __construct(
        private readonly ProductPool $productPool
    ) {
    }

    /**
     * Resolve product type from a QliroOne order item
     *
     * @param array $qliroOrderItem
     * @param \Qliro\QliroOne\Api\Product\TypeSourceProviderInterface $typeSourceProvider
     * @return string|null
     */
    public function resolve(array $qliroOrderItem, TypeSourceProviderInterface $typeSourceProvider)
    {
        if (($qliroOrderItem['Type'] ?? null) !== 'Product') {
            return null;
        }

        $sourceItem = $typeSourceProvider->getSourceItemByMerchantReference($qliroOrderItem['Metadata'] ?? []);

        if ($sourceItem) {
            $typeHash = [$sourceItem->getProduct()->getTypeId()];

            if ($parentItem = $sourceItem->getParent()) {
                $typeHash[] = $parentItem->getProduct()->getTypeId();
            }

            return implode(':', $typeHash);
        }

        return null;
    }
}
