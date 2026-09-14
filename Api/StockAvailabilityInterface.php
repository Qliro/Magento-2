<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api;

/**
 * Answers whether a line can be sold in the quantity it asks for.
 *
 * Magento answers this from Multi Source Inventory when the inventory modules are installed and
 * from `cataloginventory_stock_item` when they are not, and the two do not agree on an MSI store.
 * Everything the module tells Qliro about stock goes through here so that both setups get the
 * answer Magento itself would give.
 *
 * A line carries its product type because the answer depends on it: a type that holds no quantity
 * of its own, a bundle or a configurable, is answered by its children and is never refused here.
 */
interface StockAvailabilityInterface
{
    /**
     * Tell for each line whether it can be sold in the quantity it asks for
     *
     * @param array $lines sku => ['qty' => float, 'type' => string], the product type of the sku
     * @param int $websiteId
     * @return bool[] sku => salable, one entry for every sku asked about
     */
    public function areSalable(array $lines, int $websiteId): array;

    /**
     * Tell whether one line can be sold in the quantity it asks for
     *
     * @param string $sku
     * @param float $qty
     * @param string $productType
     * @param int $websiteId
     * @return bool
     */
    public function isSalable(string $sku, float $qty, string $productType, int $websiteId): bool;

}
