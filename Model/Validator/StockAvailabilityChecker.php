<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Validator;

use Magento\CatalogInventory\Api\StockRegistryInterface as StockRegistry;

/**
 * Decides whether a SKU can be sold for a requested qty.
 *
 */
readonly class StockAvailabilityChecker
{
    public function __construct(
        private StockRegistry $stockRegistry
    ) {
    }

    public function isAvailable(string $sku, float $qty, ?int $scopeId = null): bool
    {
        $stockItem = $this->stockRegistry->getStockItemBySku($sku, $scopeId);

        if (!$stockItem->getIsInStock()) {
            return false;
        }

        if ($stockItem->getManageStock() && !$stockItem->getBackorders() && $qty > (float) $stockItem->getQty()) {
            return false;
        }

        return true;
    }
}
