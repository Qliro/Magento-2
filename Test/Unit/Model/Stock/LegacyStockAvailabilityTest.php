<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Stock;

use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockConfigurationInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\CatalogInventory\Api\StockStateInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Stock\LegacyStockAvailability;

/**
 * @see \Qliro\QliroOne\Model\Stock\LegacyStockAvailability
 */
class LegacyStockAvailabilityTest extends TestCase
{
    /**
     * The legacy answer is the one the legacy quote validation gives, quantity and all.
     */
    public function testAsksTheStockStateForTheRequestedQuantity(): void
    {
        $stockState = $this->createMock(StockStateInterface::class);
        $stockState->expects(self::once())
            ->method('checkQty')
            ->with(42, 3.0, 1)
            ->willReturn(true);

        $availability = $this->buildAvailability($this->buildRegistry(42), $stockState);

        self::assertTrue($availability->isSalable('sku-1', 3.0, 'simple', 1));
    }

    /**
     * A quantity the stock cannot cover is refused, which the flag alone never said.
     */
    public function testRefusesAQuantityTheStockCannotCover(): void
    {
        $stockState = $this->createMock(StockStateInterface::class);
        $stockState->method('checkQty')->willReturn(false);

        $availability = $this->buildAvailability($this->buildRegistry(42), $stockState);

        self::assertFalse($availability->isSalable('sku-1', 10.0, 'simple', 1));
    }

    /**
     * A bundle keeps a stock row of its own that reads as a quantity of nothing, and Magento never
     * asks it: the children carry the stock. Asking it would report every bundle out of stock.
     */
    public function testDoesNotAskAboutATypeThatKeepsNoQuantityOfItsOwn(): void
    {
        $stockState = $this->createMock(StockStateInterface::class);
        $stockState->expects(self::never())->method('checkQty');

        $availability = $this->buildAvailability($this->buildRegistry(42), $stockState);

        self::assertTrue($availability->isSalable('bundle-1', 1.0, 'bundle', 1));
        self::assertTrue($availability->isSalable('conf-1', 1.0, 'configurable', 1));
    }

    /**
     * A sku the catalog does not hold is no statement about stock and is not refused on one.
     */
    public function testDoesNotRefuseASkuTheCatalogDoesNotHold(): void
    {
        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->method('getStockItemBySku')->willThrowException(new NoSuchEntityException());

        $stockState = $this->createMock(StockStateInterface::class);
        $stockState->expects(self::never())->method('checkQty');

        $availability = $this->buildAvailability($stockRegistry, $stockState);

        self::assertTrue($availability->isSalable('gone-1', 1.0, 'simple', 1));
    }

    /**
     * A sku with no stock row at all is the same case, there is nothing to refuse it on.
     */
    public function testDoesNotRefuseASkuWithNoStockRow(): void
    {
        $stockState = $this->createMock(StockStateInterface::class);
        $stockState->expects(self::never())->method('checkQty');

        $availability = $this->buildAvailability($this->buildRegistry(0), $stockState);

        self::assertTrue($availability->isSalable('sku-1', 1.0, 'simple', 1));
    }

    /**
     * Every line asked about comes back with an answer of its own.
     */
    public function testAnswersEveryLineItIsAskedAbout(): void
    {
        $stockState = $this->createMock(StockStateInterface::class);
        $stockState->method('checkQty')->willReturnCallback(static fn($productId, $qty): bool => $qty <= 2.0);

        $availability = $this->buildAvailability($this->buildRegistry(42), $stockState);

        self::assertSame(
            ['sku-1' => true, 'sku-2' => false],
            $availability->areSalable(
                [
                    'sku-1' => ['qty' => 1.0, 'type' => 'simple'],
                    'sku-2' => ['qty' => 5.0, 'type' => 'simple'],
                ],
                1
            )
        );
    }

    private function buildAvailability(
        StockRegistryInterface $stockRegistry,
        StockStateInterface $stockState
    ): LegacyStockAvailability {
        $stockConfiguration = $this->createMock(StockConfigurationInterface::class);
        $stockConfiguration->method('getIsQtyTypeIds')->willReturn([
            'simple' => 1,
            'virtual' => 1,
            'downloadable' => 1,
            'configurable' => 0,
            'bundle' => 0,
            'grouped' => 0,
        ]);

        return new LegacyStockAvailability($stockRegistry, $stockState, $stockConfiguration);
    }

    private function buildRegistry(int $itemId): StockRegistryInterface
    {
        $stockItem = $this->createMock(StockItemInterface::class);
        $stockItem->method('getItemId')->willReturn($itemId ?: null);
        $stockItem->method('getProductId')->willReturn($itemId);

        $stockRegistry = $this->createMock(StockRegistryInterface::class);
        $stockRegistry->method('getStockItemBySku')->willReturn($stockItem);

        return $stockRegistry;
    }
}
