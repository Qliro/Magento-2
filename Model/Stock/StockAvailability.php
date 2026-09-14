<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Stock;

use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\ObjectManagerInterface;
use Qliro\QliroOne\Api\StockAvailabilityInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * Sends the stock question to whichever inventory the store actually runs on.
 *
 * The inventory modules are optional, `composer.json` requires none of them, so the Multi Source
 * Inventory reader is built through the object manager and only once the modules that answer for
 * it are enabled. Nothing else in the module names a Multi Source Inventory class.
 *
 * The same question asked again is answered from the last answer. One validate callback asks about
 * a cart up to four times, once for the callback's own decision and once for each handler that
 * builds a line of it, and every one of those is the same question about the same cart.
 */
class StockAvailability implements StockAvailabilityInterface
{
    /**
     * The modules that answer the salability question, all three are needed to ask it
     */
    private const INVENTORY_MODULES = [
        'Magento_InventorySales',
        'Magento_InventoryCatalog',
        'Magento_InventoryConfiguration',
    ];

    /**
     * @var StockAvailabilityInterface|null
     */
    private ?StockAvailabilityInterface $backend = null;

    /**
     * The last question asked, as the lines and the website that were asked about
     *
     * @var array{lines: array, websiteId: int}|null
     */
    private ?array $lastQuestion = null;

    /**
     * The answer that question got, sku => salable
     *
     * @var bool[]
     */
    private array $lastAnswer = [];

    /**
     * Inject dependencies
     *
     * @param ObjectManagerInterface $objectManager
     * @param ModuleManager $moduleManager
     * @param LegacyStockAvailability $legacyStockAvailability
     * @param LogManager $logManager
     */
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly ModuleManager $moduleManager,
        private readonly LegacyStockAvailability $legacyStockAvailability,
        private readonly LogManager $logManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function areSalable(array $lines, int $websiteId): array
    {
        if ($this->lastQuestion === ['lines' => $lines, 'websiteId' => $websiteId]) {
            return $this->lastAnswer;
        }

        $answers = [];

        try {
            $answers = $this->resolveBackend()->areSalable($lines, $websiteId);
        } catch (\Throwable $exception) {
            $this->logManager->error(
                sprintf('Could not read stock, treating the lines as salable: %s', $exception->getMessage())
            );
        }

        /*
         * A sku left without an answer is not refused, and that holds for a failure that keeps
         * happening as much as for one that passes. Magento checks stock again for real when it
         * places the order and this reader is only asked ahead of that, so an inventory the module
         * cannot read costs a store nothing, while refusing on it would decline every order the
         * store has. The line in the log is what says the inventory needs looking at.
         */
        $result = [];

        foreach (array_keys($lines) as $sku) {
            $result[$sku] = $answers[$sku] ?? true;
        }

        $this->lastQuestion = ['lines' => $lines, 'websiteId' => $websiteId];
        $this->lastAnswer = $result;

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
     * Build the reader for the inventory this store runs on, once
     *
     * @return StockAvailabilityInterface
     */
    private function resolveBackend(): StockAvailabilityInterface
    {
        if ($this->backend !== null) {
            return $this->backend;
        }

        if ($this->isInventoryEnabled()) {
            try {
                return $this->backend = $this->objectManager->get(MsiStockAvailability::class);
            } catch (\Throwable $exception) {
                $this->logManager->error(
                    sprintf('Could not build the MSI stock reader: %s', $exception->getMessage())
                );
            }
        }

        return $this->backend = $this->legacyStockAvailability;
    }

    /**
     * Tell whether the store has the inventory modules that answer for salability
     *
     * @return bool
     */
    private function isInventoryEnabled(): bool
    {
        foreach (self::INVENTORY_MODULES as $module) {
            if (!$this->moduleManager->isEnabled($module)) {
                return false;
            }
        }

        return true;
    }
}
