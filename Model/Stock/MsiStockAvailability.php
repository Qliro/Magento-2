<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Stock;

use Magento\Framework\ObjectManagerInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Magento\InventorySalesApi\Api\AreProductsSalableForRequestedQtyInterface;
use Magento\InventorySalesApi\Api\Data\IsProductSalableForRequestedQtyRequestInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use Qliro\QliroOne\Api\StockAvailabilityInterface;

/**
 * Asks Multi Source Inventory, the way Magento asks it when it places the order.
 *
 * `Magento\InventorySales\Plugin\CatalogInventory\StockManagement\ProcessRegisterProductsSalePlugin`
 * is the model followed here: resolve the stock of the website's sales channel, drop the lines whose
 * product type holds no source item, and ask the salable-for-requested-qty service about the rest.
 *
 * Only the constructor names the inventory classes, and the class is built through the object
 * manager by `StockAvailability`, so a store without the inventory modules never loads it. The
 * request objects are built through the object manager for the same reason: a generated factory
 * named in a constructor is looked for when the DI compiler runs, whether or not it can exist.
 */
class MsiStockAvailability implements StockAvailabilityInterface
{
    /**
     * The stock of each website's sales channel, which is configuration and does not move
     *
     * @var int[]
     */
    private array $stockIds = [];

    /**
     * Inject dependencies
     *
     * @param AreProductsSalableForRequestedQtyInterface $areProductsSalableForRequestedQty
     * @param ObjectManagerInterface $objectManager
     * @param StockResolverInterface $stockResolver
     * @param IsSourceItemManagementAllowedForProductTypeInterface $isSourceItemManagementAllowed
     * @param WebsiteRepositoryInterface $websiteRepository
     */
    public function __construct(
        private readonly AreProductsSalableForRequestedQtyInterface $areProductsSalableForRequestedQty,
        private readonly ObjectManagerInterface $objectManager,
        private readonly StockResolverInterface $stockResolver,
        private readonly IsSourceItemManagementAllowedForProductTypeInterface $isSourceItemManagementAllowed,
        private readonly WebsiteRepositoryInterface $websiteRepository
    ) {
    }

    /**
     * @inheritDoc
     */
    public function areSalable(array $lines, int $websiteId): array
    {
        $result = [];
        $requests = [];

        foreach ($lines as $sku => $line) {
            $productType = (string)($line['type'] ?? '');

            // A type that holds no source item is answered by its children, the same lines MSI
            // skips when it takes the stock for an order
            if ($productType !== '' && !$this->isSourceItemManagementAllowed->execute($productType)) {
                $result[$sku] = true;
                continue;
            }

            $requests[] = $this->objectManager->create(
                IsProductSalableForRequestedQtyRequestInterface::class,
                ['sku' => (string)$sku, 'qty' => (float)($line['qty'] ?? 0.0)]
            );
        }

        if (!$requests) {
            return $result;
        }

        foreach ($this->areProductsSalableForRequestedQty->execute($requests, $this->stockId($websiteId)) as $salable) {
            $result[$salable->getSku()] = $salable->isSalable();
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    public function isSalable(string $sku, float $qty, string $productType, int $websiteId): bool
    {
        $lines = [$sku => ['qty' => $qty, 'type' => $productType]];

        return $this->areSalable($lines, $websiteId)[$sku] ?? true;
    }

    /**
     * Resolve the stock the website sells from
     *
     * @param int $websiteId
     * @return int
     */
    private function stockId(int $websiteId): int
    {
        return $this->stockIds[$websiteId] ??= (int)$this->stockResolver->execute(
            SalesChannelInterface::TYPE_WEBSITE,
            $this->websiteRepository->getById($websiteId)->getCode()
        )->getStockId();
    }
}
