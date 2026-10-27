<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Product\Type;

use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterfaceFactory;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Product\ProductPool;
use Qliro\QliroOne\Model\Product\Type\QuoteSourceProvider;
use Qliro\QliroOne\Model\Product\Type\TypeSourceItem;
use Qliro\QliroOne\Model\Product\VatRate;
use Qliro\QliroOne\Service\RecurringPayments\Data as RecurringDataService;

/**
 * The cart item a line reference stands for, on the quote side
 *
 * @see \Qliro\QliroOne\Model\Product\Type\QuoteSourceProvider::getSourceItemByMerchantReference
 */
class QuoteSourceProviderTest extends TestCase
{
    public function testFindsTheItemByTheIdInTheLineMetadata(): void
    {
        $item = $this->quoteItem();

        self::assertSame($item, $this->lookUp(['qliro' => 'checkout', 'quoteItems' => ['11:SKU-1' => '11:SKU-1']], [11 => $item]));
    }

    /**
     * A bare sku in the metadata used to make the sku the whole metadata array
     */
    public function testFindsTheItemByABareSkuInTheLineMetadata(): void
    {
        $item = $this->quoteItem();

        self::assertSame($item, $this->lookUp(['quoteItems' => ['SKU-1' => 'SKU-1']], [], ['SKU-1' => $item]));
    }

    /**
     * A sku may hold the separator, only the part in front of the first one is the id
     */
    public function testKeepsASkuWithASeparatorWhole(): void
    {
        $item = $this->quoteItem();

        self::assertSame($item, $this->lookUp('99:AB:12', [], ['AB:12' => $item]));
    }

    public function testReturnsNullWithoutAReference(): void
    {
        self::assertNull($this->lookUp(['qliro' => 'checkout'], [], []));
        self::assertNull($this->lookUp('NO-SUCH-SKU', [], []));
    }

    /**
     * @param string|array $reference
     * @param array<int, QuoteItem> $byId
     * @param array<string, QuoteItem> $bySku
     */
    private function lookUp($reference, array $byId, array $bySku = []): ?QuoteItem
    {
        $products = [];
        $quote = $this->createMock(Quote::class);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getItemById')->willReturnCallback(static fn($id) => $byId[(int)$id] ?? false);
        $quote->method('getItemByProduct')->willReturnCallback(
            static function (Product $product) use ($bySku, &$products) {
                return $bySku[array_search($product, $products, true)] ?? false;
            }
        );

        $productPool = $this->createMock(ProductPool::class);
        $productPool->method('getProduct')->willReturnCallback(
            function (string $sku) use ($bySku, &$products): Product {
                if (!isset($bySku[$sku])) {
                    throw new NoSuchEntityException();
                }

                return $products[$sku] ??= $this->createMock(Product::class);
            }
        );

        $provider = $this->getMockBuilder(QuoteSourceProvider::class)
            ->setConstructorArgs([
                $productPool,
                $this->createMock(TypeSourceItemInterfaceFactory::class),
                $this->createMock(Config::class),
                $this->createMock(RecurringDataService::class),
                $this->createMock(VatRate::class),
            ])
            ->onlyMethods(['generateSourceItem'])
            ->getMock();
        $provider->method('generateSourceItem')->willReturnCallback(
            static fn($item): TypeSourceItem => (new TypeSourceItem())->setItem($item)
        );
        $provider->setQuote($quote);

        return $provider->getSourceItemByMerchantReference($reference)?->getItem();
    }

    private function quoteItem(): QuoteItem
    {
        return $this->getMockBuilder(QuoteItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }
}
