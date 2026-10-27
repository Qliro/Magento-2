<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Product\Type;

use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterfaceFactory;
use Qliro\QliroOne\Model\Product\ProductPool;
use Qliro\QliroOne\Model\Product\Type\OrderSourceProvider;
use Qliro\QliroOne\Model\Product\Type\TypeSourceItem;

/**
 * The order item a line reference stands for, on the order side
 *
 * @see \Qliro\QliroOne\Model\Product\Type\OrderSourceProvider::getSourceItemByMerchantReference
 */
class OrderSourceProviderTest extends TestCase
{
    /**
     * A bare sku, the reference of an order placed before 1.7.42, finds the item with that sku
     */
    public function testFindsTheItemBySkuWhenTheReferenceHasNoItemId(): void
    {
        $item = $this->orderItem(11, 'SKU-1', 5);

        $sourceItem = $this->provider([$this->orderItem(10, 'SKU-0', 4), $item], ['SKU-1' => 5])
            ->getSourceItemByMerchantReference('SKU-1');

        self::assertSame($item, $sourceItem?->getItem());
    }

    /**
     * An item whose sku changed since the order is found by the id of the product with the sku
     */
    public function testFindsTheItemByProductIdWhenNoSkuMatches(): void
    {
        $item = $this->orderItem(11, 'OLD-SKU', 5);

        $sourceItem = $this->provider([$item], ['SKU-1' => 5])->getSourceItemByMerchantReference('SKU-1');

        self::assertSame($item, $sourceItem?->getItem());
    }

    /**
     * A configurable line is built from the child, and the parent carries the child's sku too
     */
    public function testFindsTheChildOfAConfigurable(): void
    {
        [$parent, $child] = $this->configurable();

        $sourceItem = $this->provider([$parent, $child], ['CHILD-SKU' => 5])
            ->getSourceItemByMerchantReference('CHILD-SKU');

        self::assertSame($child, $sourceItem?->getItem());
        self::assertSame($parent, $sourceItem?->getParent()?->getItem());
    }

    public function testFindsTheChildOfAConfigurableWhenTheProductIsGone(): void
    {
        [$parent, $child] = $this->configurable();

        $sourceItem = $this->provider([$parent, $child])->getSourceItemByMerchantReference('CHILD-SKU');

        self::assertSame($child, $sourceItem?->getItem());
    }

    public function testReturnsNullWhenNothingMatches(): void
    {
        $provider = $this->provider([$this->orderItem(11, 'SKU-1', 5)], ['SKU-2' => 6]);

        self::assertNull($provider->getSourceItemByMerchantReference('SKU-2'));
    }

    public function testReturnsNullWhenTheProductIsGone(): void
    {
        $provider = $this->provider([$this->orderItem(11, 'SKU-1', 5)]);

        self::assertNull($provider->getSourceItemByMerchantReference('SKU-2'));
    }

    /**
     * The handlers read the product of the item, so an item whose product is gone is not returned
     */
    public function testSkipsAnItemWhoseProductIsGone(): void
    {
        $item = $this->orderItem(11, 'SKU-1', 5);
        $item->setData('product', null);
        $item->setProduct(null);

        self::assertNull($this->provider([$item])->getSourceItemByMerchantReference('SKU-1'));
    }

    public function testSkipsAChildWhoseParentProductIsGone(): void
    {
        [$parent, $child] = $this->configurable();
        $parent->setProduct(null);

        self::assertNull($this->provider([$parent, $child], ['CHILD-SKU' => 5])->getSourceItemByMerchantReference('CHILD-SKU'));
    }

    public function testFindsTheItemByItsQuoteItemId(): void
    {
        $item = $this->orderItem(11, 'SKU-1', 5);

        $sourceItem = $this->provider([$this->orderItem(10, 'SKU-1', 5), $item])
            ->getSourceItemByMerchantReference('11:SKU-1');

        self::assertSame($item, $sourceItem?->getItem());
    }

    /**
     * An id no item of the order carries, an order placed from another cart, falls back to the sku
     */
    public function testFallsBackToTheSkuWhenNoItemHasTheId(): void
    {
        $item = $this->orderItem(11, 'SKU-1', 5);

        $sourceItem = $this->provider([$item])->getSourceItemByMerchantReference('99:SKU-1');

        self::assertSame($item, $sourceItem?->getItem());
    }

    /**
     * The handlers pass the line metadata, which holds the reference under quoteItems
     */
    public function testReadsTheReferenceFromLineMetadata(): void
    {
        $item = $this->orderItem(11, 'SKU-1', 5);
        $provider = $this->provider([$item]);

        $sourceItem = $provider->getSourceItemByMerchantReference(
            ['qliro' => 'checkout', 'quoteItems' => ['11:SKU-1' => '11:SKU-1']]
        );

        self::assertSame($item, $sourceItem?->getItem());
        self::assertNull($provider->getSourceItemByMerchantReference(['qliro' => 'checkout']));
        self::assertNull($provider->getSourceItemByMerchantReference(['quoteItems' => []]));
        self::assertNull($provider->getSourceItemByMerchantReference(''));
    }

    /**
     * @param OrderItem[] $items
     * @param array<string, int> $productIds product id by sku in the catalog
     */
    private function provider(array $items, array $productIds = []): OrderSourceProvider
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getItemByQuoteItemId', 'getAllItems', 'getStoreId'])
            ->getMock();
        $order->method('getStoreId')->willReturn(1);
        $order->method('getAllItems')->willReturn($items);
        $order->method('getItemByQuoteItemId')->willReturnCallback(
            static function ($quoteItemId) use ($items): ?OrderItem {
                foreach ($items as $item) {
                    if ($item->getQuoteItemId() == $quoteItemId) {
                        return $item;
                    }
                }

                return null;
            }
        );

        $productPool = $this->createMock(ProductPool::class);
        $productPool->method('getProduct')->willReturnCallback(
            function (string $sku) use ($productIds): Product {
                if (!isset($productIds[$sku])) {
                    throw new NoSuchEntityException();
                }

                return $this->product($productIds[$sku]);
            }
        );

        $sourceItemFactory = $this->createMock(TypeSourceItemInterfaceFactory::class);
        $sourceItemFactory->method('create')->willReturnCallback(static fn(): TypeSourceItem => new TypeSourceItem());

        $provider = new OrderSourceProvider($productPool, $sourceItemFactory);
        $provider->setOrder($order);

        return $provider;
    }

    private function orderItem(int $quoteItemId, string $sku, int $productId): OrderItem
    {
        $item = $this->getMockBuilder(OrderItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $item->setData([
            'quote_item_id' => $quoteItemId,
            'sku' => $sku,
            'name' => $sku,
            'product_type' => 'simple',
            'product_id' => $productId,
            'product' => $this->product($productId),
            'qty_ordered' => 1,
        ]);

        return $item;
    }

    /**
     * @return OrderItem[] the parent and its child
     */
    private function configurable(): array
    {
        $parent = $this->orderItem(20, 'CHILD-SKU', 50);
        $child = $this->orderItem(21, 'CHILD-SKU', 5);
        $child->setParentItem($parent);

        return [$parent, $child];
    }

    private function product(int $id): Product&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($id);

        return $product;
    }
}
