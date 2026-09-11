<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Stock;

use Magento\Framework\ObjectManagerInterface;
use Magento\InventoryApi\Api\Data\StockInterface;
use Magento\InventoryConfigurationApi\Model\IsSourceItemManagementAllowedForProductTypeInterface;
use Magento\InventorySalesApi\Api\AreProductsSalableForRequestedQtyInterface;
use Magento\InventorySalesApi\Api\Data\IsProductSalableForRequestedQtyRequestInterface;
use Magento\InventorySalesApi\Api\Data\IsProductSalableForRequestedQtyResultInterface;
use Magento\InventorySalesApi\Api\Data\SalesChannelInterface;
use Magento\InventorySalesApi\Api\StockResolverInterface;
use Magento\Store\Api\Data\WebsiteInterface;
use Magento\Store\Api\WebsiteRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Stock\MsiStockAvailability;

/**
 * @see \Qliro\QliroOne\Model\Stock\MsiStockAvailability
 */
class MsiStockAvailabilityTest extends TestCase
{
    /**
     * @var array<int, array{sku: string, qty: float}>
     */
    private array $requested = [];

    /**
     * @var int|null
     */
    private ?int $askedStockId = null;

    /**
     * @var int
     */
    private int $stockLookups = 0;

    /**
     * A salable line is salable, and the question goes to the stock of the website's sales channel.
     */
    public function testAsksTheStockOfTheWebsiteSalesChannel(): void
    {
        $availability = $this->buildAvailability(['sku-1' => true]);

        self::assertTrue($availability->isSalable('sku-1', 2.0, 'simple', 3));
        self::assertSame(7, $this->askedStockId);
        self::assertSame([['sku' => 'sku-1', 'qty' => 2.0]], $this->requested);
    }

    /**
     * A line MSI does not consider salable is refused, which is the whole point of asking MSI.
     */
    public function testRefusesALineMsiDoesNotConsiderSalable(): void
    {
        $availability = $this->buildAvailability(['sku-1' => false]);

        self::assertFalse($availability->isSalable('sku-1', 1.0, 'simple', 3));
    }

    /**
     * The quantity in the cart is part of the question, so a cart asking for more than the stock
     * can sell is refused even though the product itself is in stock.
     */
    public function testRefusesACartQuantityAboveTheSalableQuantity(): void
    {
        $availability = $this->buildAvailability(['sku-1' => static fn(float $qty): bool => $qty <= 4.0]);

        self::assertTrue($availability->isSalable('sku-1', 4.0, 'simple', 3));
        self::assertFalse($availability->isSalable('sku-1', 5.0, 'simple', 3));
    }

    /**
     * A product type that holds no source item is answered by its children, the same lines MSI
     * skips when it takes the stock for an order. Asking about it would refuse a bundle on a sku
     * the catalog never held.
     */
    public function testDoesNotAskAboutATypeThatHoldsNoSourceItem(): void
    {
        $availability = $this->buildAvailability([]);

        self::assertTrue($availability->isSalable('bundle-1-2-3', 1.0, 'bundle', 3));
        self::assertSame([], $this->requested);
    }

    /**
     * The lines that can be asked about are asked about even when another line cannot be.
     */
    public function testAnswersTheAskableLinesAlongsideTheRest(): void
    {
        $availability = $this->buildAvailability(['sku-1' => false]);

        self::assertSame(
            ['conf-1' => true, 'sku-1' => false],
            $availability->areSalable(
                [
                    'sku-1' => ['qty' => 1.0, 'type' => 'simple'],
                    'conf-1' => ['qty' => 1.0, 'type' => 'configurable'],
                ],
                3
            )
        );
    }

    /**
     * Which stock a website sells from is configuration, so it is resolved once.
     */
    public function testResolvesTheStockOfAWebsiteOnce(): void
    {
        $availability = $this->buildAvailability(['sku-1' => true, 'sku-2' => true]);

        $availability->isSalable('sku-1', 1.0, 'simple', 3);
        $availability->isSalable('sku-2', 1.0, 'simple', 3);

        self::assertSame(1, $this->stockLookups);
    }

    /**
     * @param array<string, bool|callable> $salable sku => answer, or a callable taking the quantity
     * @return MsiStockAvailability
     */
    private function buildAvailability(array $salable): MsiStockAvailability
    {
        $isSourceItemManagementAllowed = $this->createMock(
            IsSourceItemManagementAllowedForProductTypeInterface::class
        );
        $isSourceItemManagementAllowed->method('execute')
            ->willReturnCallback(static fn(string $type): bool => $type === 'simple');

        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('create')->willReturnCallback(
            function (string $type, array $data): IsProductSalableForRequestedQtyRequestInterface {
                self::assertSame(IsProductSalableForRequestedQtyRequestInterface::class, $type);
                $this->requested[] = ['sku' => $data['sku'], 'qty' => $data['qty']];

                $request = $this->createMock(IsProductSalableForRequestedQtyRequestInterface::class);
                $request->method('getSku')->willReturn($data['sku']);
                $request->method('getQty')->willReturn($data['qty']);

                return $request;
            }
        );

        $areProductsSalable = $this->createMock(AreProductsSalableForRequestedQtyInterface::class);
        $areProductsSalable->method('execute')->willReturnCallback(
            function (array $requests, int $stockId) use ($salable): array {
                $this->askedStockId = $stockId;
                $results = [];

                foreach ($requests as $request) {
                    $answer = $salable[$request->getSku()] ?? false;

                    $result = $this->createMock(IsProductSalableForRequestedQtyResultInterface::class);
                    $result->method('getSku')->willReturn($request->getSku());
                    $result->method('isSalable')->willReturn(
                        is_callable($answer) ? $answer($request->getQty()) : (bool)$answer
                    );

                    $results[] = $result;
                }

                return $results;
            }
        );

        $stock = $this->createMock(StockInterface::class);
        $stock->method('getStockId')->willReturn(7);

        $stockResolver = $this->createMock(StockResolverInterface::class);
        $stockResolver->method('execute')->willReturnCallback(
            function (string $type, string $code) use ($stock): StockInterface {
                self::assertSame(SalesChannelInterface::TYPE_WEBSITE, $type);
                self::assertSame('nordic', $code);
                $this->stockLookups++;

                return $stock;
            }
        );

        $website = $this->createMock(WebsiteInterface::class);
        $website->method('getCode')->willReturn('nordic');

        $websiteRepository = $this->createMock(WebsiteRepositoryInterface::class);
        $websiteRepository->method('getById')->with(3)->willReturn($website);

        return new MsiStockAvailability(
            $areProductsSalable,
            $objectManager,
            $stockResolver,
            $isSourceItemManagementAllowed,
            $websiteRepository
        );
    }
}
