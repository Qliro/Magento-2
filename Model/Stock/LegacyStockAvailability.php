<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Stock;

use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Api\StockStateInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Qliro\QliroOne\Api\StockAvailabilityInterface;

/**
 * Reads stock from `cataloginventory_stock_item`, for a store without the inventory modules.
 *
 * The website is passed on but decides nothing: both `StockRegistry` and `StockState` replace the
 * scope they are given with the default one, because legacy stock keeps a single scope. A store
 * that wants stock per website is the store that wants the inventory modules.
 */
class LegacyStockAvailability implements StockAvailabilityInterface
{
    /**
     * Inject dependencies
     *
     * @param StockRegistryInterface $stockRegistry
     * @param StockStateInterface $stockState
     * @param StockConfigurationInterface $stockConfiguration
     */
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly StockStateInterface $stockState,
        private readonly StockConfigurationInterface $stockConfiguration
    ) {
    }

    /**
     * @inheritDoc
     */
    public function areSalable(array $lines, int $websiteId): array
    {
        $result = [];

        foreach ($lines as $sku => $line) {
            $result[$sku] = $this->isSalable(
                (string)$sku,
                (float)($line['qty'] ?? 0.0),
                (string)($line['type'] ?? ''),
                $websiteId
            );
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function isSalable(string $sku, float $qty, string $productType, int $websiteId): bool
    {
        if (!$this->holdsQuantity($productType)) {
            return true;
        }

        try {
            $stockItem = $this->stockRegistry->getStockItemBySku($sku, $websiteId);
        } catch (NoSuchEntityException $exception) {
            // Nothing in the catalog to refuse the line on, so it is not refused
            return true;
        }

        if (!$stockItem->getItemId()) {
            return true;
        }

        // checkQty asks what the legacy quote validation asks: manage stock, is in stock, min qty, backorders
        return (bool)$this->stockState->checkQty((int)$stockItem->getProductId(), $qty, $websiteId);
    }

    /**
     * Tell whether a product of this type keeps a quantity of its own
     *
     * A bundle, a configurable and a grouped product do not. Their own stock row reads as a
     * quantity of nothing, and Magento never asks it: the children carry the stock. This is the
     * same rule the inventory modules apply, they read it from this very configuration.
     *
     * @param string $productType
     * @return bool
     */
    private function holdsQuantity(string $productType): bool
    {
        $types = array_keys(array_filter($this->stockConfiguration->getIsQtyTypeIds()));

        return $productType === '' || in_array($productType, $types, true);
    }
}
