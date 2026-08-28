<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Admin\Builder\Handler;

use Magento\Sales\Model\Order;
use Magento\Tax\Model\Config as TaxConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Helper\Data as QliroHelper;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\AppliedRulesHandler;
use Qliro\QliroOne\Model\QliroOrder\DiscountAmountResolver;
use Qliro\QliroOne\Model\QliroOrder\Item;

/**
 * The order management side of the same discount line, so that a capture cannot ask for a figure
 * the checkout never sent.
 *
 * @see \Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\AppliedRulesHandler
 */
class AppliedRulesHandlerTest extends TestCase
{
    private AppliedRulesHandler $handler;

    protected function setUp(): void
    {
        $this->handler = $this->buildHandler();
    }

    /**
     * The Nordic setup, where Magento states the VAT part of the discount outright.
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
     * With prices excluding tax and tax calculated after the discount the order carries the same
     * evidence the quote does, so the capture carries the same VAT the checkout charged.
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
     * Only the first capture carries the order level lines, the rest would double them.
     */
    public function testSkipsAnOrderThatIsNotOnItsFirstCapture(): void
    {
        $order = $this->buildOrder([]);
        $order->setData('first_capture_flag', false);

        self::assertSame([], $this->handler->handle([], $order));
    }

    /**
     * Without a discount there is nothing to add.
     */
    public function testSkipsOrderWithoutDiscount(): void
    {
        self::assertSame([], $this->handler->handle([], $this->buildOrder(['discount_amount' => '0.0000'])));
    }

    /**
     * Anything that is not an order is left alone.
     */
    public function testIgnoresNonOrderSubject(): void
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

        return new AppliedRulesHandler($itemFactory, $qliroHelper, new DiscountAmountResolver($taxConfig));
    }

    /**
     * @param array<string, string> $totals
     */
    private function buildDiscountLine(array $totals): QliroOrderItemInterface
    {
        $orderItems = $this->handler->handle([], $this->buildOrder($totals));

        self::assertCount(1, $orderItems);

        return $orderItems[0];
    }

    /**
     * The default order is 100.00 of goods at 25 percent with 10.00 off, the figure the ticket is
     * about. The totals are real order data, so the resolver is exercised through the getters it
     * meets in production.
     *
     * @param array<string, string> $totals
     */
    private function buildOrder(array $totals): Order&MockObject
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $order->setData($totals + [
            'first_capture_flag' => true,
            'applied_rule_ids' => '10',
            'store_id' => 1,
            'discount_amount' => '-10.0000',
            'discount_tax_compensation_amount' => '0.0000',
            'subtotal' => '100.0000',
            'subtotal_incl_tax' => '125.0000',
            'shipping_amount' => '0.0000',
            'shipping_incl_tax' => '0.0000',
            'tax_amount' => '0.0000',
        ]);

        return $order;
    }
}
