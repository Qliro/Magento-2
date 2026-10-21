<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder\Handler;

use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Tax\Model\Config as TaxConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Helper\Data as QliroHelper;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\Builder\Handler\AppliedRulesHandler;
use Qliro\QliroOne\Model\QliroOrder\DiscountAmountResolver;
use Qliro\QliroOne\Model\QliroOrder\DiscountLines;
use Qliro\QliroOne\Model\QliroOrder\LineReference;
use Qliro\QliroOne\Model\QliroOrder\LineVatRate;
use Qliro\QliroOne\Model\QliroOrder\Item;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Builder\Handler\AppliedRulesHandler
 */
class AppliedRulesHandlerTest extends TestCase
{
    private ManagerInterface&MockObject $eventManager;
    private AppliedRulesHandler $handler;

    protected function setUp(): void
    {
        $this->eventManager = $this->createMock(ManagerInterface::class);
        $this->handler = $this->buildHandler();
    }

    /**
     * The Nordic setup, an 18.00 product with a 0.72 discount inc VAT where Magento's own tax
     * compensation is already rounded to 0.14, so the ex VAT amount is 0.58.
     */
    public function testSendsTheStatedVatPartWithATwoDecimalRate(): void
    {
        $discountLine = $this->buildDiscountLine([
            'discount_amount' => '-0.7200',
            'discount_tax_compensation_amount' => '0.1400',
        ]);

        self::assertSame(-0.72, $discountLine->getPricePerItemIncVat());
        self::assertSame(-0.58, $discountLine->getPricePerItemExVat());
        self::assertSame(24.14, $discountLine->getVatRate());
        self::assertSame('DSC_ORDER', $discountLine->getMerchantReference());
    }

    /**
     * With prices excluding tax and tax calculated after the discount, Magento states no VAT part
     * and the discount amount is ex VAT. Sending it on both sides charges the customer the VAT of
     * the discount on top of Magento's grand total.
     */
    public function testGrossesUpTheDiscountWhenPricesExcludeTax(): void
    {
        $this->handler = $this->buildHandler(priceIncludesTax: false, applyTaxAfterDiscount: true);

        $discountLine = $this->buildDiscountLine(['tax_amount' => '22.5000']);

        self::assertSame(-12.5, $discountLine->getPricePerItemIncVat());
        self::assertSame(-10.0, $discountLine->getPricePerItemExVat());
        self::assertSame(25.0, $discountLine->getVatRate());
    }

    /**
     * The three configurations that were already correct keep sending what they sent.
     *
     * @dataProvider unchangedConfigurationProvider
     */
    public function testLeavesTheOtherConfigurationsAsTheyWere(
        bool $priceIncludesTax,
        bool $applyTaxAfterDiscount
    ): void {
        $this->handler = $this->buildHandler($priceIncludesTax, $applyTaxAfterDiscount);

        $discountLine = $this->buildDiscountLine(['tax_amount' => '25.0000']);

        self::assertSame(-10.0, $discountLine->getPricePerItemIncVat());
        self::assertSame(-10.0, $discountLine->getPricePerItemExVat());
        self::assertSame(0.0, $discountLine->getVatRate());
    }

    /**
     * @return array<string, array{0: bool, 1: bool}>
     */
    public static function unchangedConfigurationProvider(): array
    {
        return [
            'prices include tax, tax before the discount' => [true, false],
            'prices exclude tax, tax before the discount' => [false, false],
            'prices include tax, tax after the discount' => [true, true],
        ];
    }

    /**
     * A discount line is built with the type, quantity and description Qliro expects, and the
     * build event fires so third parties can still adjust it.
     */
    public function testBuildsASingleDiscountLineAndDispatchesTheBuildEvent(): void
    {
        $this->eventManager->expects(self::once())
            ->method('dispatch')
            ->with('qliroone_order_item_build_after', self::anything());

        $shipping = (new Item())->setMerchantReference('flatrate_flatrate')->setType(QliroOrderItemInterface::TYPE_SHIPPING);

        $orderItems = $this->handler->handle([$shipping], $this->buildQuote([]));

        self::assertCount(2, $orderItems);
        self::assertSame($shipping, $orderItems[0]);
        self::assertSame(QliroOrderItemInterface::TYPE_DISCOUNT, $orderItems[1]->getType());
        self::assertSame(1.0, $orderItems[1]->getQuantity());
        self::assertSame('DSC_ORDER', $orderItems[1]->getDescription());
    }

    /**
     * Without a discount there is nothing to add, and no event to dispatch.
     */
    public function testSkipsQuoteWithoutDiscount(): void
    {
        $this->eventManager->expects(self::never())->method('dispatch');

        self::assertSame([], $this->handler->handle([], $this->buildQuote(['discount_amount' => '0.0000'])));
    }

    /**
     * A virtual quote carries its totals on the billing address.
     */
    public function testUsesTheBillingAddressForAVirtualQuote(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('isVirtual')->willReturn(true);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getAllItems')->willReturn([new DataObject(['tax_percent' => 25.0])]);
        $quote->method('getBillingAddress')->willReturn($this->buildAddress([
            'discount_amount' => '-0.7200',
            'discount_tax_compensation_amount' => '0.1400',
        ]));
        $quote->expects(self::never())->method('getShippingAddress');

        $orderItems = $this->handler->handle([], $quote);

        self::assertSame(24.14, $orderItems[0]->getVatRate());
        self::assertSame('DSC_ORDER', $orderItems[0]->getMerchantReference());
    }

    /**
     * Three units at 199.00 with 100.00 off: 33.33 a unit, and the öre left over on a line of its
     * own, so a capture of any number of units can take exactly their discount.
     */
    public function testSplitsTheDiscountOfAProductLinePerUnit(): void
    {
        $item = $this->quoteItem(5, ['discount_amount' => '100.0000', 'discount_tax_compensation_amount' => '20.0000']);

        $lines = $this->handler->handle([$this->product('5:SKU', 3)], $this->buildQuote(
            ['discount_amount' => '-100.0000', 'discount_tax_compensation_amount' => '20.0000'],
            [5 => $item]
        ));

        self::assertSame(['5:SKU', 'DSC_5:SKU', 'DSC_5:SKU_R'], $this->references($lines));
        self::assertSame(3.0, $lines[1]->getQuantity());
        self::assertSame(-33.33, $lines[1]->getPricePerItemIncVat());
        self::assertSame(-26.66, $lines[1]->getPricePerItemExVat());
        self::assertSame(25.0, $lines[1]->getVatRate());
        self::assertSame(1.0, $lines[2]->getQuantity());
        self::assertSame(-0.01, $lines[2]->getPricePerItemIncVat());
    }

    /**
     * A discount that divides into the units needs no remainder line.
     */
    public function testNeedsNoRemainderWhenTheDiscountDivides(): void
    {
        $item = $this->quoteItem(5, ['discount_amount' => '30.0000', 'discount_tax_compensation_amount' => '6.0000']);

        $lines = $this->handler->handle([$this->product('5:SKU', 3)], $this->buildQuote(
            ['discount_amount' => '-30.0000', 'discount_tax_compensation_amount' => '6.0000'],
            [5 => $item]
        ));

        self::assertSame(['5:SKU', 'DSC_5:SKU'], $this->references($lines));
        self::assertSame(-10.0, $lines[1]->getPricePerItemIncVat());
    }

    /**
     * A configurable's line is its child, the discount is on the parent.
     */
    public function testTakesTheDiscountOfAConfigurableFromItsParent(): void
    {
        $parent = $this->quoteItem(4, ['discount_amount' => '20.0000', 'discount_tax_compensation_amount' => '4.0000'], 'configurable');
        $child = $this->quoteItem(5, [], 'simple', $parent);

        $lines = $this->handler->handle([$this->product('5:CHILD', 2)], $this->buildQuote(
            ['discount_amount' => '-20.0000', 'discount_tax_compensation_amount' => '4.0000'],
            [4 => $parent, 5 => $child]
        ));

        self::assertSame(['5:CHILD', 'DSC_5:CHILD'], $this->references($lines));
        self::assertSame(-10.0, $lines[1]->getPricePerItemIncVat());
    }

    /**
     * A bundle priced by its children carries their discounts summed up, its own line gets none.
     */
    public function testLeavesTheDiscountOfADynamicBundleToItsChildren(): void
    {
        $bundle = $this->quoteItem(4, ['discount_amount' => '10.0000', 'discount_tax_compensation_amount' => '2.0000'], 'bundle', null, true);
        $child = $this->quoteItem(5, ['discount_amount' => '10.0000', 'discount_tax_compensation_amount' => '2.0000'], 'simple', $bundle, true);

        $lines = $this->handler->handle([$this->product('4:BUNDLE', 1), $this->product('5:PART', 1)], $this->buildQuote(
            ['discount_amount' => '-10.0000', 'discount_tax_compensation_amount' => '2.0000'],
            [4 => $bundle, 5 => $child]
        ));

        self::assertSame(['4:BUNDLE', '5:PART', 'DSC_5:PART'], $this->references($lines));
    }

    /**
     * A rule that discounts the shipping too puts that part on a line of its own.
     */
    public function testPutsTheShippingDiscountOnALineOfItsOwn(): void
    {
        $item = $this->quoteItem(5, ['discount_amount' => '30.0000', 'discount_tax_compensation_amount' => '6.0000']);

        $lines = $this->handler->handle([$this->product('5:SKU', 3)], $this->buildQuote(
            [
                'discount_amount' => '-40.0000',
                'discount_tax_compensation_amount' => '8.0000',
                'shipping_discount_amount' => '10.0000',
                'shipping_discount_tax_compensation_amount' => '2.0000',
                'shipping_amount' => '47.2000',
                'shipping_incl_tax' => '59.0000',
            ],
            [5 => $item]
        ));

        self::assertSame(['5:SKU', 'DSC_5:SKU', 'DSC_SHIPPING'], $this->references($lines));
        self::assertSame(-10.0, $lines[2]->getPricePerItemIncVat());
        self::assertSame(-8.0, $lines[2]->getPricePerItemExVat());
    }

    /**
     * The lines add up to the cart discount, nothing goes on an order level line.
     */
    public function testTheLinesAddUpToTheCartDiscount(): void
    {
        $item = $this->quoteItem(5, ['discount_amount' => '100.0000', 'discount_tax_compensation_amount' => '20.0000']);

        $lines = $this->handler->handle([$this->product('5:SKU', 3)], $this->buildQuote(
            ['discount_amount' => '-100.0000', 'discount_tax_compensation_amount' => '20.0000'],
            [5 => $item]
        ));

        $total = 0.0;
        foreach (array_slice($lines, 1) as $line) {
            $total += $line->getPricePerItemIncVat() * $line->getQuantity();
        }

        self::assertSame(-100.0, round($total, 2));
    }

    /**
     * A cart the discount lines would take past Qliro's line limit keeps one discount line.
     */
    public function testKeepsOneDiscountLinePastTheLineLimit(): void
    {
        $products = [];
        $items = [];

        for ($id = 1; $id <= 150; $id++) {
            $products[] = $this->product($id . ':SKU', 3);
            $items[$id] = $this->quoteItem($id, ['discount_amount' => '10.0000', 'discount_tax_compensation_amount' => '2.0000']);
        }

        $lines = $this->handler->handle($products, $this->buildQuote(
            ['discount_amount' => '-1500.0000', 'discount_tax_compensation_amount' => '300.0000'],
            $items
        ));

        self::assertCount(151, $lines);
        self::assertSame('DSC_ORDER', $lines[150]->getMerchantReference());
        self::assertSame(-1500.0, $lines[150]->getPricePerItemIncVat());
    }

    /**
     * Exactly at the limit the cart collapses too, the rounding line added after this needs room.
     */
    public function testKeepsOneDiscountLineWhenTheLinesWouldReachTheLimit(): void
    {
        $products = [];
        $items = [];

        for ($id = 1; $id <= 100; $id++) {
            $products[] = $this->product($id . ':SKU', 3);
            $items[$id] = $this->quoteItem($id, ['discount_amount' => '30.0000', 'discount_tax_compensation_amount' => '6.0000']);
        }

        $lines = $this->handler->handle($products, $this->buildQuote(
            ['discount_amount' => '-3000.0000', 'discount_tax_compensation_amount' => '600.0000'],
            $items
        ));

        self::assertCount(101, $lines);
        self::assertSame('DSC_ORDER', $lines[100]->getMerchantReference());
    }

    private function product(string $reference, int $quantity): Item
    {
        return (new Item())
            ->setMerchantReference($reference)
            ->setType(QliroOrderItemInterface::TYPE_PRODUCT)
            ->setQuantity($quantity)
            ->setPricePerItemIncVat(199.0)
            ->setPricePerItemExVat(159.2);
    }

    /**
     * @param array<string, string> $data
     */
    private function quoteItem(
        int $id,
        array $data,
        string $type = 'simple',
        ?QuoteItem $parent = null,
        bool $childrenCalculated = false
    ): QuoteItem&MockObject {
        $item = $this->getMockBuilder(QuoteItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getParentItem', 'isChildrenCalculated', 'getProductType'])
            ->getMock();
        $item->method('getParentItem')->willReturn($parent);
        $item->method('isChildrenCalculated')->willReturn($childrenCalculated);
        $item->method('getProductType')->willReturn($type);
        $item->setData($data + ['item_id' => $id, 'tax_percent' => 25.0]);

        return $item;
    }

    /**
     * @param QliroOrderItemInterface[] $lines
     * @return string[]
     */
    private function references(array $lines): array
    {
        return array_map(static fn($line): string => $line->getMerchantReference(), $lines);
    }

    /**
     * Anything that is not a quote is left alone.
     */
    public function testIgnoresNonQuoteSubject(): void
    {
        self::assertSame(['untouched'], $this->handler->handle(['untouched'], null));
    }

    /**
     * Prices including tax with the tax applied after the discount is the Nordic default, so the
     * handler is built that way unless a test says otherwise.
     */
    private function buildHandler(
        bool $priceIncludesTax = true,
        bool $applyTaxAfterDiscount = true
    ): AppliedRulesHandler {
        $itemFactory = $this->createMock(QliroOrderItemInterfaceFactory::class);
        $itemFactory->method('create')->willReturnCallback(static fn(): Item => new Item());

        $qliroHelper = $this->createMock(QliroHelper::class);
        $qliroHelper->method('formatPrice')
            ->willReturnCallback(static fn($value): string => number_format((float)$value, 2, '.', ''));

        $taxConfig = $this->createMock(TaxConfig::class);
        $taxConfig->method('priceIncludesTax')->willReturn($priceIncludesTax);
        $taxConfig->method('applyTaxAfterDiscount')->willReturn($applyTaxAfterDiscount);

        $resolver = new DiscountAmountResolver($taxConfig, $this->createMock(LogManager::class), new LineVatRate());

        return new AppliedRulesHandler(
            $this->eventManager,
            $resolver,
            new DiscountLines($itemFactory, $qliroHelper, $resolver, new LineVatRate()),
            new LineReference(),
            $this->createMock(LogManager::class)
        );
    }

    /**
     * @param array<string, string> $totals
     */
    private function buildDiscountLine(array $totals): QliroOrderItemInterface
    {
        $orderItems = $this->handler->handle([], $this->buildQuote($totals));

        self::assertCount(1, $orderItems);

        return $orderItems[0];
    }

    /**
     * @param array<string, string> $totals
     */
    private function buildQuote(array $totals, array $items = []): Quote&MockObject
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isVirtual', 'getShippingAddress', 'getStoreId', 'getAllItems', 'getItemById'])
            ->addMethods(['getAppliedRuleIds'])
            ->getMock();
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getShippingAddress')->willReturn($this->buildAddress($totals));
        $quote->method('getAllItems')->willReturn([new DataObject(['tax_percent' => 25.0])]);
        $quote->method('getAppliedRuleIds')->willReturn('10');
        $quote->method('getItemById')->willReturnCallback(static fn($id) => $items[(int)$id] ?? false);

        return $quote;
    }

    /**
     * Magento stores the collected totals as strings. The default cart is 100.00 of goods at 25
     * percent with 10.00 off, which is the figure the ticket is about.
     *
     * @param array<string, string> $totals
     */
    private function buildAddress(array $totals): DataObject
    {
        return new DataObject($totals + [
            'discount_amount' => '-10.0000',
            'discount_tax_compensation_amount' => '0.0000',
            'subtotal' => '100.0000',
            'subtotal_incl_tax' => '125.0000',
            'shipping_amount' => '0.0000',
            'shipping_incl_tax' => '0.0000',
            'tax_amount' => '0.0000',
        ]);
    }
}
