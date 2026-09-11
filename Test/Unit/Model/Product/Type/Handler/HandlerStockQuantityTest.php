<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Product\Type\Handler;

use Magento\Catalog\Model\Product;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Api\Product\TypeHandlerInterface;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterface;
use Qliro\QliroOne\Api\StockAvailabilityInterface;
use Qliro\QliroOne\Helper\Data as QliroHelper;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Product\Type\Handler\BundleHandler;
use Qliro\QliroOne\Model\Product\Type\Handler\ConfigurableHandler;
use Qliro\QliroOne\Model\Product\VatRate;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\LineVatRate;

/**
 * The quantity a child line asks the stock about, for the handlers that build one.
 *
 * @see \Qliro\QliroOne\Model\Product\Type\Handler\ConfigurableHandler
 * @see \Qliro\QliroOne\Model\Product\Type\Handler\BundleHandler
 */
class HandlerStockQuantityTest extends TestCase
{
    /**
     * @var array<int, array<string, array{qty: float, type: string}>>
     */
    private array $asked = [];

    /**
     * @var \Magento\Quote\Model\Quote\Item[]
     */
    private array $cartItems = [];

    /**
     * A configurable line is built from the child simple, whose own quantity is one per parent, so
     * the quantity the cart asks for is the parent's. Asking about the child's alone would tell
     * Qliro the line is in stock and let the validate callback decline it a moment later.
     */
    public function testAsksAboutTheWholeQuantityOfAConfigurableLine(): void
    {
        $handler = $this->buildHandler(ConfigurableHandler::class);

        $handler->prepareMetaData($this->buildChildSourceItem('child-1', 1.0, 10.0));

        self::assertSame([['child-1' => ['qty' => 10.0, 'type' => 'simple']]], $this->asked);
    }

    /**
     * A bundle child says how many of it go in one bundle, so the cart asks for that many times
     * the number of bundles. The validate callback counts it the same way.
     */
    public function testAsksAboutTheWholeQuantityOfABundleChildLine(): void
    {
        $handler = $this->buildHandler(BundleHandler::class);

        $handler->prepareMetaData($this->buildChildSourceItem('child-1', 2.0, 3.0));

        self::assertSame([['child-1' => ['qty' => 6.0, 'type' => 'simple']]], $this->asked);
    }

    /**
     * The whole cart is the question, so the same sku on two lines is one quantity, the one the
     * validate callback asks about. A line on its own would be asked for half of it.
     */
    public function testAsksAboutTheWholeCartAndNotTheLineAlone(): void
    {
        $handler = $this->buildHandler(ConfigurableHandler::class);

        $first = $this->buildChildSourceItem('child-1', 1.0, 3.0);
        $second = $this->buildChildSourceItem('child-1', 1.0, 5.0, $first->getItem()->getQuote());

        $handler->prepareMetaData($second);

        self::assertSame([['child-1' => ['qty' => 8.0, 'type' => 'simple']]], $this->asked);
    }

    /**
     * A cart changed in place is a different question, and is asked as it now stands.
     */
    public function testAsksAboutTheCartAsItStands(): void
    {
        $handler = $this->buildHandler(ConfigurableHandler::class);

        $first = $this->buildChildSourceItem('child-1', 1.0, 3.0);
        $handler->prepareMetaData($first);

        $second = $this->buildChildSourceItem('child-2', 1.0, 5.0, $first->getItem()->getQuote());
        $handler->prepareMetaData($second);

        self::assertSame(
            [
                ['child-1' => ['qty' => 3.0, 'type' => 'simple']],
                [
                    'child-1' => ['qty' => 3.0, 'type' => 'simple'],
                    'child-2' => ['qty' => 5.0, 'type' => 'simple'],
                ],
            ],
            $this->asked
        );
    }

    /**
     * @param class-string<TypeHandlerInterface> $handlerClass
     * @return TypeHandlerInterface
     */
    private function buildHandler(string $handlerClass): TypeHandlerInterface
    {
        $stockAvailability = $this->createMock(StockAvailabilityInterface::class);
        $stockAvailability->method('areSalable')->willReturnCallback(
            function (array $lines): array {
                $this->asked[] = $lines;

                return array_map(static fn(): bool => true, $lines);
            }
        );

        $itemFactory = $this->createMock(QliroOrderItemInterfaceFactory::class);
        $itemFactory->method('create')->willReturnCallback(static fn(): Item => new Item());

        $qliroHelper = $this->createMock(QliroHelper::class);
        $qliroHelper->method('formatPrice')
            ->willReturnCallback(static fn($value): string => number_format((float)$value, 2, '.', ''));

        $config = $this->createMock(Config::class);
        $config->method('isIngridEnabled')->willReturn(true);

        return new $handlerClass(
            $itemFactory,
            $qliroHelper,
            $config,
            $this->createMock(VatRate::class),
            new LineVatRate(),
            $stockAvailability
        );
    }

    private function buildChildSourceItem(
        string $sku,
        float $childQty,
        float $parentQty,
        ?Quote $quote = null
    ): TypeSourceItemInterface {
        $quoteItem = $this->getMockBuilder(QuoteItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTotalQty', 'getQuote', 'getSku', 'getProductType'])
            ->addMethods(['getDiscountAmount'])
            ->getMock();
        $quoteItem->method('getDiscountAmount')->willReturn(0.0);
        $quoteItem->method('getTotalQty')->willReturn($childQty * $parentQty);
        $quoteItem->method('getSku')->willReturn($sku);
        $quoteItem->method('getProductType')->willReturn('simple');

        $store = $this->createMock(Store::class);
        $store->method('getWebsiteId')->willReturn(2);

        if ($quote === null) {
            $quote = $this->createMock(Quote::class);
            $quote->method('getStore')->willReturn($store);
            $quote->method('getAllItems')->willReturnCallback(fn(): array => $this->cartItems);
        }

        $this->cartItems[] = $quoteItem;
        $quoteItem->method('getQuote')->willReturn($quote);

        $product = $this->createMock(Product::class);
        $product->method('getStoreId')->willReturn(1);
        $product->method('getStore')->willReturn($store);
        $product->method('getSku')->willReturn($sku);
        $product->method('getWeight')->willReturn(1.0);

        $parent = $this->createMock(TypeSourceItemInterface::class);
        $parent->method('getQty')->willReturn($parentQty);
        $parent->method('getItem')->willReturn($quoteItem);

        $item = $this->createMock(TypeSourceItemInterface::class);
        $item->method('getId')->willReturn(9);
        $item->method('getSku')->willReturn($sku);
        $item->method('getType')->willReturn('simple');
        $item->method('getQty')->willReturn($childQty);
        $item->method('getProduct')->willReturn($product);
        $item->method('getItem')->willReturn($quoteItem);
        $item->method('getParent')->willReturn($parent);
        $item->method('getSubscription')->willReturn(false);

        return $item;
    }
}
