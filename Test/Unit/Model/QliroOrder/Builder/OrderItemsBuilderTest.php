<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder;

use Magento\Catalog\Model\Product;
use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Tax\Helper\Data as TaxHelper;
use Magento\Tax\Model\Calculation as TaxCalculation;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Builder\OrderItemHandlerInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterfaceFactory;
use Qliro\QliroOne\Helper\Data as QliroHelper;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Product\ProductPool;
use Qliro\QliroOne\Model\Product\Type\Handler\BundleHandler;
use Qliro\QliroOne\Model\Product\Type\Handler\ConfigurableHandler;
use Qliro\QliroOne\Model\Product\Type\Handler\DefaultHandler;
use Qliro\QliroOne\Model\Product\Type\QuoteSourceProvider;
use Qliro\QliroOne\Model\Product\Type\TypePoolHandler;
use Qliro\QliroOne\Model\Product\Type\TypeResolver;
use Qliro\QliroOne\Model\Product\Type\TypeSourceItem;
use Qliro\QliroOne\Model\Product\VatRate;
use Qliro\QliroOne\Model\QliroOrder\Builder\OrderItemsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\LineVatRate;
use Qliro\QliroOne\Service\RecurringPayments\Data as RecurringDataService;

/**
 * The order lines a cart is sent to Qliro as, built through the chain the checkout uses: the
 * source provider that reads the cart, the type pool wired in `etc/di.xml` and the type handlers.
 * The amounts are what the buyer is charged, so they are pinned here rather than at each part.
 *
 * @see \Qliro\QliroOne\Model\QliroOrder\Builder\OrderItemsBuilder
 */
class OrderItemsBuilderTest extends TestCase
{
    /**
     * A simple line goes out with the cart's own two amounts and the rate Magento calculated,
     * at the quantity in the cart.
     */
    public function testSendsASimpleLineWithTheAmountsAndRateOfTheCartLine(): void
    {
        $item = $this->quoteItem(['id' => 11, 'name' => 'Simple', 'sku' => 'SKU-1', 'type' => 'simple',
            'qty' => 2.0, 'incVat' => 125.0, 'exVat' => 100.0, 'taxPercent' => 25.0]);

        $lines = $this->buildBuilder()->setQuote($this->quote([$item]))->create();

        self::assertCount(1, $lines);
        self::assertSame(QliroOrderItemInterface::TYPE_PRODUCT, $lines[0]->getType());
        self::assertSame(125.0, $lines[0]->getPricePerItemIncVat());
        self::assertSame(100.0, $lines[0]->getPricePerItemExVat());
        self::assertSame(25.0, $lines[0]->getVatRate());
        self::assertSame(2.0, $lines[0]->getQuantity());
        self::assertSame('Simple', $lines[0]->getDescription());
    }

    /**
     * A price with more decimals than Qliro accepts is rounded on the line.
     */
    public function testRoundsTheAmountsToTheTwoDecimalsQliroAccepts(): void
    {
        $item = $this->quoteItem(['id' => 12, 'name' => 'Odd', 'sku' => 'SKU-2', 'type' => 'simple',
            'qty' => 1.0, 'incVat' => 5.9875, 'exVat' => 4.79, 'taxPercent' => 25.0]);

        $lines = $this->buildBuilder()->setQuote($this->quote([$item]))->create();

        self::assertSame(5.99, $lines[0]->getPricePerItemIncVat());
        self::assertSame(4.79, $lines[0]->getPricePerItemExVat());
    }

    /**
     * The rate is the one the cart states, not one read back off the amounts that are sent. The
     * cart holds 4.79 taxed at 25, which is 5.9875 and goes out as 5.99, and 5.99 over 4.79 reads
     * back as 25.05, a rate no jurisdiction charges.
     */
    public function testSendsTheRateTheCartStatesRatherThanOneReadOffTheAmounts(): void
    {
        $item = $this->quoteItem(['id' => 13, 'name' => 'Odd', 'sku' => 'SKU-3', 'type' => 'simple',
            'qty' => 1.0, 'incVat' => 5.99, 'exVat' => 4.79, 'taxPercent' => 25.0]);

        $lines = $this->buildBuilder()->setQuote($this->quote([$item]))->create();

        self::assertSame(25.0, $lines[0]->getVatRate());
    }

    /**
     * A configurable is one line, not two: the parent has no handler in the pool, and the child
     * carries the parent's price and the parent's quantity. Sending both would charge twice.
     */
    public function testSendsAConfigurableAsOneLinePricedFromItsParent(): void
    {
        $parent = $this->quoteItem(['id' => 21, 'name' => 'Shirt', 'sku' => 'SHIRT', 'type' => 'configurable',
            'qty' => 3.0, 'incVat' => 250.0, 'exVat' => 200.0, 'taxPercent' => 25.0]);
        // A child carries its own quantity per parent times the parent's, so the two differ
        $child = $this->quoteItem(['id' => 22, 'name' => 'Shirt blue', 'sku' => 'SHIRT-BLUE', 'type' => 'simple',
            'qty' => 6.0, 'incVat' => 0.0, 'exVat' => 0.0, 'taxPercent' => null], $parent);

        $lines = $this->buildBuilder()->setQuote($this->quote([$parent, $child]))->create();

        self::assertCount(1, $lines);
        self::assertSame(250.0, $lines[0]->getPricePerItemIncVat());
        self::assertSame(200.0, $lines[0]->getPricePerItemExVat());
        self::assertSame(25.0, $lines[0]->getVatRate());
        self::assertSame(3.0, $lines[0]->getQuantity());
        self::assertSame('Shirt blue', $lines[0]->getDescription());
    }

    /**
     * A bundle priced from its children sends the bundle line at zero and each child at its own
     * price, so the lines add up to what the cart charges rather than to twice it.
     */
    public function testSendsABundlePricedFromItsChildrenWithTheBundleLineAtZero(): void
    {
        $bundle = $this->quoteItem(['id' => 31, 'name' => 'Kit', 'sku' => 'KIT', 'type' => 'bundle',
            'qty' => 1.0, 'incVat' => 150.0, 'exVat' => 120.0, 'taxPercent' => 25.0, 'priceType' => 0]);
        $child = $this->quoteItem(['id' => 32, 'name' => 'Part', 'sku' => 'PART', 'type' => 'simple',
            'qty' => 2.0, 'incVat' => 75.0, 'exVat' => 60.0, 'taxPercent' => 25.0], $bundle);

        $lines = $this->buildBuilder()->setQuote($this->quote([$bundle, $child]))->create();

        self::assertCount(2, $lines);
        self::assertSame(0.0, $lines[0]->getPricePerItemIncVat());
        self::assertSame(0.0, $lines[0]->getPricePerItemExVat());
        self::assertSame(1.0, $lines[0]->getQuantity());
        self::assertSame(75.0, $lines[1]->getPricePerItemIncVat());
        self::assertSame(60.0, $lines[1]->getPricePerItemExVat());
        self::assertSame(25.0, $lines[1]->getVatRate());
        self::assertSame(2.0, $lines[1]->getQuantity());
    }

    /**
     * A bundle with a price of its own keeps it, and its children are sent at the zero the cart
     * prices them at.
     */
    public function testKeepsThePriceOfABundleThatCarriesOne(): void
    {
        $bundle = $this->quoteItem(['id' => 41, 'name' => 'Box', 'sku' => 'BOX', 'type' => 'bundle',
            'qty' => 1.0, 'incVat' => 500.0, 'exVat' => 400.0, 'taxPercent' => 25.0, 'priceType' => 1]);
        $child = $this->quoteItem(['id' => 42, 'name' => 'Part', 'sku' => 'PART', 'type' => 'simple',
            'qty' => 1.0, 'incVat' => 0.0, 'exVat' => 0.0, 'taxPercent' => 25.0], $bundle);

        $lines = $this->buildBuilder()->setQuote($this->quote([$bundle, $child]))->create();

        self::assertSame(500.0, $lines[0]->getPricePerItemIncVat());
        self::assertSame(400.0, $lines[0]->getPricePerItemExVat());
        self::assertSame(0.0, $lines[1]->getPricePerItemIncVat());
    }

    /**
     * A line the type pool has no handler for is left out rather than sent as an empty line.
     */
    public function testLeavesOutALineNoHandlerClaims(): void
    {
        $item = $this->quoteItem(['id' => 51, 'name' => 'Card', 'sku' => 'CARD', 'type' => 'giftcard',
            'qty' => 1.0, 'incVat' => 100.0, 'exVat' => 100.0, 'taxPercent' => 0.0]);

        self::assertSame([], $this->buildBuilder()->setQuote($this->quote([$item]))->create());
    }

    /**
     * Qliro identifies a line by its merchant reference, so a line that ends up without one is
     * left out: it is what an observer on the build event strips a line with.
     */
    public function testLeavesOutALineThatLostItsMerchantReference(): void
    {
        $eventManager = $this->createMock(ManagerInterface::class);
        $eventManager->method('dispatch')->willReturnCallback(
            static function (string $name, array $data): void {
                $data['container']->setMerchantReference('');
            }
        );

        $item = $this->quoteItem(['id' => 61, 'name' => 'Simple', 'sku' => 'SKU-1', 'type' => 'simple',
            'qty' => 1.0, 'incVat' => 125.0, 'exVat' => 100.0, 'taxPercent' => 25.0]);

        self::assertSame([], $this->buildBuilder([], $eventManager)->setQuote($this->quote([$item]))->create());
    }

    /**
     * The handlers run over the finished lines, in the order they are wired, and each one is given
     * what the one before it returned. That is where the discount line comes from. Anything wired
     * in that is not a handler is passed over rather than called.
     */
    public function testRunsTheHandlersOverTheFinishedLinesInOrder(): void
    {
        $item = $this->quoteItem(['id' => 71, 'name' => 'Simple', 'sku' => 'SKU-1', 'type' => 'simple',
            'qty' => 1.0, 'incVat' => 125.0, 'exVat' => 100.0, 'taxPercent' => 25.0]);

        $lines = $this->buildBuilder([
            $this->appendingHandler('first'),
            $this->appendingHandler('second'),
            new \stdClass(),
        ])->setQuote($this->quote([$item]))->create();

        self::assertCount(3, $lines);
        self::assertSame('first', $lines[1]->getMerchantReference());
        self::assertSame('second', $lines[2]->getMerchantReference());
    }

    /**
     * The builder is one shared instance, so it releases the cart it built from. Without that a
     * later call with no cart of its own would silently send the previous buyer's lines.
     */
    public function testReleasesTheCartItBuiltFrom(): void
    {
        $item = $this->quoteItem(['id' => 81, 'name' => 'Simple', 'sku' => 'SKU-1', 'type' => 'simple',
            'qty' => 1.0, 'incVat' => 125.0, 'exVat' => 100.0, 'taxPercent' => 25.0]);

        $builder = $this->buildBuilder();
        $builder->setQuote($this->quote([$item]))->create();

        $this->expectException(\LogicException::class);
        $builder->create();
    }

    public function testRefusesToBuildWithoutACart(): void
    {
        $this->expectException(\LogicException::class);

        $this->buildBuilder()->create();
    }

    /**
     * The source provider is told the cart is gone as well. It reads a cart line once and keeps
     * what it read, so a build that ended without telling it would leave the next build in the
     * same request reading the cart it no longer has.
     */
    public function testTellsTheSourceProviderTheCartIsGone(): void
    {
        $quote = $this->quote([$this->quoteItem(['id' => 91, 'name' => 'Simple', 'sku' => 'SKU-1',
            'type' => 'simple', 'qty' => 1.0, 'incVat' => 125.0, 'exVat' => 100.0, 'taxPercent' => 25.0])]);

        $carts = [];
        $provider = $this->createMock(QuoteSourceProvider::class);
        $provider->method('setQuote')->willReturnCallback(
            static function ($value) use (&$carts): void {
                $carts[] = $value;
            }
        );
        $provider->method('generateSourceItem')->willReturn($this->sourceItem());

        $this->buildBuilder([], null, $provider)->setQuote($quote)->create();

        self::assertSame([$quote, null], $carts);
    }

    /**
     * The pool the test builds mirrors `etc/di.xml`, and a line is priced by whichever handler it
     * names, so the two must not drift apart.
     */
    public function testTheTypePoolMatchesTheOneWiredInDi(): void
    {
        $di = new \SimpleXMLElement(file_get_contents(__DIR__ . '/../../../../../etc/di.xml'));
        $wired = [];

        foreach ($di->xpath('//type[@name="Qliro\QliroOne\Model\Product\Type\TypePoolHandler"]//item') as $item) {
            $wired[(string)$item['name']] = trim((string)$item) ?: null;
        }

        $expected = [
            'virtual' => DefaultHandler::class,
            'simple' => DefaultHandler::class,
            'virtual:configurable' => ConfigurableHandler::class,
            'simple:configurable' => ConfigurableHandler::class,
            'configurable' => null,
            'virtual:bundle' => BundleHandler::class,
            'simple:bundle' => BundleHandler::class,
            'bundle' => BundleHandler::class,
        ];

        // The order the types are wired in decides nothing, only which handler each one names
        ksort($expected);
        ksort($wired);

        self::assertSame($expected, $wired);
    }

    /**
     * @param OrderItemHandlerInterface[]|object[] $handlers
     */
    private function buildBuilder(
        array $handlers = [],
        ?ManagerInterface $eventManager = null,
        ?QuoteSourceProvider $sourceProvider = null
    ): OrderItemsBuilder {
        return new OrderItemsBuilder(
            $this->createMock(TaxHelper::class),
            $this->createMock(TaxCalculation::class),
            $this->typePool(),
            $this->itemFactory(),
            $this->qliroHelper(),
            $sourceProvider ?? $this->sourceProvider(),
            $eventManager ?? $this->createMock(ManagerInterface::class),
            $handlers
        );
    }

    /**
     * The pool as `etc/di.xml` wires it, see testTheTypePoolMatchesTheOneWiredInDi
     */
    private function typePool(): TypePoolHandler
    {
        $default = $this->typeHandler(DefaultHandler::class);
        $configurable = $this->typeHandler(ConfigurableHandler::class);
        $bundle = $this->typeHandler(BundleHandler::class);

        return new TypePoolHandler($this->createMock(TypeResolver::class), [
            'virtual' => $default,
            'simple' => $default,
            'virtual:configurable' => $configurable,
            'simple:configurable' => $configurable,
            'configurable' => null,
            'virtual:bundle' => $bundle,
            'simple:bundle' => $bundle,
            'bundle' => $bundle,
        ]);
    }

    /**
     * @param class-string<DefaultHandler> $class
     */
    private function typeHandler(string $class): DefaultHandler
    {
        $config = $this->createMock(Config::class);
        $config->method('isIngridEnabled')->willReturn(false);

        $vatRate = $this->createMock(VatRate::class);
        $vatRate->method('getVatRateForProduct')->willReturn(0.0);

        return new $class($this->itemFactory(), $this->qliroHelper(), $config, $vatRate, new LineVatRate());
    }

    /**
     * A read cart line, the shape the provider hands to the type pool
     */
    private function sourceItem(): TypeSourceItem
    {
        $product = $this->createMock(Product::class);
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getStoreId')->willReturn(1);

        return (new TypeSourceItem())
            ->setId(91)
            ->setName('Simple')
            ->setSku('SKU-1')
            ->setType('simple')
            ->setQty(1.0)
            ->setPriceInclTax(125.0)
            ->setPriceExclTax(100.0)
            ->setProduct($product)
            ->setItem(null);
    }

    private function sourceProvider(): QuoteSourceProvider
    {
        $sourceItemFactory = $this->createMock(TypeSourceItemInterfaceFactory::class);
        $sourceItemFactory->method('create')->willReturnCallback(static fn(): TypeSourceItem => new TypeSourceItem());

        $config = $this->createMock(Config::class);
        $config->method('isUseRecurring')->willReturn(false);

        $vatRate = $this->createMock(VatRate::class);
        $vatRate->method('getVatRateForProduct')->willReturn(0.0);

        return new QuoteSourceProvider(
            $this->createMock(ProductPool::class),
            $sourceItemFactory,
            $config,
            $this->createMock(RecurringDataService::class),
            $vatRate
        );
    }

    private function itemFactory(): QliroOrderItemInterfaceFactory&MockObject
    {
        $factory = $this->createMock(QliroOrderItemInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(static fn(): Item => new Item());

        return $factory;
    }

    private function qliroHelper(): QliroHelper&MockObject
    {
        $helper = $this->createMock(QliroHelper::class);
        $helper->method('formatPrice')
            ->willReturnCallback(static fn($value): string => number_format((float)$value, 2, '.', ''));

        return $helper;
    }

    /**
     * A handler that appends a line of its own, the way the discount handler does
     */
    private function appendingHandler(string $reference): OrderItemHandlerInterface
    {
        $handler = $this->createMock(OrderItemHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            static function (array $lines) use ($reference): array {
                $lines[] = (new Item())->setMerchantReference($reference);

                return $lines;
            }
        );

        return $handler;
    }

    /**
     * @param QuoteItem[] $items
     */
    private function quote(array $items): Quote&MockObject
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getAllItems')->willReturn($items);

        return $quote;
    }

    private function quoteItem(array $data, ?QuoteItem $parent = null): QuoteItem&MockObject
    {
        $product = $this->getMockBuilder(Product::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTypeId', 'getStoreId'])
            ->addMethods(['getPriceType'])
            ->getMock();
        $product->method('getTypeId')->willReturn($data['type']);
        $product->method('getStoreId')->willReturn(1);
        $product->method('getPriceType')->willReturn($data['priceType'] ?? null);

        $item = $this->getMockBuilder(QuoteItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getItemId', 'getName', 'getPrice', 'getQty', 'getSku', 'getProductType', 'getProduct', 'getParentItem'])
            ->addMethods(['getPriceInclTax', 'getTaxPercent'])
            ->getMock();

        $item->method('getItemId')->willReturn($data['id']);
        $item->method('getName')->willReturn($data['name']);
        $item->method('getSku')->willReturn($data['sku']);
        $item->method('getProductType')->willReturn($data['type']);
        $item->method('getQty')->willReturn($data['qty']);
        $item->method('getPriceInclTax')->willReturn($data['incVat']);
        $item->method('getPrice')->willReturn($data['exVat']);
        $item->method('getTaxPercent')->willReturn($data['taxPercent']);
        $item->method('getProduct')->willReturn($product);
        $item->method('getParentItem')->willReturn($parent);

        return $item;
    }
}
