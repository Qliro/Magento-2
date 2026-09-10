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
use Magento\Tax\Model\Config as TaxConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Helper\Data as QliroHelper;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\Builder\Handler\AppliedRulesHandler;
use Qliro\QliroOne\Model\QliroOrder\DiscountAmountResolver;
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
        self::assertSame('DSC_10', $discountLine->getMerchantReference());
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

        $orderItems = $this->handler->handle(['existing line'], $this->buildQuote([]));

        self::assertCount(2, $orderItems);
        self::assertSame('existing line', $orderItems[0]);
        self::assertSame(QliroOrderItemInterface::TYPE_DISCOUNT, $orderItems[1]->getType());
        self::assertSame(1.0, $orderItems[1]->getQuantity());
        self::assertSame('DSC_10', $orderItems[1]->getDescription());
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
        self::assertSame('DSC_QUOTE_DISCOUNT', $orderItems[0]->getMerchantReference());
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

        return new AppliedRulesHandler(
            $itemFactory,
            $qliroHelper,
            $this->eventManager,
            new DiscountAmountResolver($taxConfig, $this->createMock(LogManager::class), new LineVatRate())
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
    private function buildQuote(array $totals): Quote&MockObject
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isVirtual', 'getShippingAddress', 'getStoreId', 'getAllItems'])
            ->addMethods(['getAppliedRuleIds'])
            ->getMock();
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getShippingAddress')->willReturn($this->buildAddress($totals));
        $quote->method('getAllItems')->willReturn([new DataObject(['tax_percent' => 25.0])]);
        $quote->method('getAppliedRuleIds')->willReturn('10');

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
