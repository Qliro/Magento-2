<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Validator;

use Magento\Catalog\Api\Data\ProductInterface as Product;
use Magento\CatalogInventory\Api\StockRegistryInterface as StockRegistry;

/**
 * Decides whether a product can be sold for a requested qty.
 *
 */
readonly class StockAvailabilityChecker
{
    public function __construct(
        private StockRegistry $stockRegistry
    ) {
    }

    public function isAvailable(Product $product, float $qty): bool
    {
        $stockItem = $this->stockRegistry->getStockItem(
            $product->getId(),
            $product->getStore()->getWebsiteId()
        );

        if (!$stockItem->getIsInStock()) {
            return false;
        }

        if ($stockItem->getManageStock() && !$stockItem->getBackorders() && $qty > (float) $stockItem->getQty()) {
            return false;
        }

        return true;
    }
}
