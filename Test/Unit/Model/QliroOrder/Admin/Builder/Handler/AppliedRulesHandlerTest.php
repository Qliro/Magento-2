<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Admin\Builder\Handler;

use Magento\Framework\DataObject;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Tax\Model\Config as TaxConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Helper\Data as QliroHelper;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\AppliedRulesHandler;
use Qliro\QliroOne\Model\QliroOrder\DiscountAmountResolver;
use Qliro\QliroOne\Model\QliroOrder\DiscountLines;
use Qliro\QliroOne\Model\QliroOrder\LineVatRate;
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
     * An order placed before 1.7.18 was reserved with the discount VAT free, and Qliro refuses a
     * capture whose lines disagree with the reservation, so the line goes out the way it went out
     * then. The over-charge it was reserved with stays, an uncapturable order would be worse.
     */
    public function testReproducesTheReservedLineForAnOrderPlacedBeforeTheGrossUp(): void
    {
        $this->handler = $this->buildHandler(priceIncludesTax: false, applyTaxAfterDiscount: true);

        $discountLine = $this->buildDiscountLine(
            ['tax_amount' => '22.5000'],
            reservationCarriesDiscountVat: false
        );

        self::assertSame(-10.0, $discountLine->getPricePerItemIncVat());
        self::assertSame(-10.0, $discountLine->getPricePerItemExVat());
        self::assertSame(0.0, $discountLine->getVatRate());
    }

    /**
     * The stamp only gates the configuration the gross-up applies to. Where Magento states the VAT
     * part outright the reservation already carried it, on 1.7.17 as much as now.
     */
    public function testTheStampDoesNotChangeAStatedVatPart(): void
    {
        $discountLine = $this->buildDiscountLine(
            ['discount_amount' => '-0.7200', 'discount_tax_compensation_amount' => '0.1400'],
            reservationCarriesDiscountVat: false
        );

        self::assertSame(-0.72, $discountLine->getPricePerItemIncVat());
        self::assertSame(-0.58, $discountLine->getPricePerItemExVat());
        self::assertSame(24.14, $discountLine->getVatRate());
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
     * The ticket's order: 319.50 off the cart, and a first invoice of one product and the shipping
     * worth 211.10. With the discount it is -108.40, which Qliro refuses, so the line waits.
     */
    public function testKeepsTheSingleReservedLineFromAFirstCaptureItWouldTurnNegative(): void
    {
        $order = $this->buildOrder($this->ticketTotals() + [AppliedRulesHandler::FINAL_CAPTURE_FLAG => false]);

        $lines = $this->handler->handle([$this->product('7:SKU-A', 1, 152.10), $this->shipping(59.00)], $order);

        self::assertSame(['7:SKU-A', 'flatrate_flatrate'], $this->references($lines));
    }

    /**
     * The first capture large enough to carry it takes the line whole, as it was reserved.
     */
    public function testSendsTheSingleReservedLineWithTheFirstCaptureThatStaysPositive(): void
    {
        $order = $this->buildOrder(
            $this->ticketTotals() + ['first_capture_flag' => false, AppliedRulesHandler::FINAL_CAPTURE_FLAG => false],
            true,
            [Config::QLIROONE_ADDITIONAL_INFO_CAPTURED_UNITS => ['7:SKU-A' => 1, 'flatrate_flatrate' => 1]]
        );

        $lines = $this->handler->handle([$this->product('8:SKU-B', 3, 152.10)], $order);

        self::assertSame(['8:SKU-B', 'DSC_10'], $this->references($lines));
        self::assertSame(-319.5, $lines[1]->getPricePerItemIncVat());
        self::assertSame(1.0, $lines[1]->getQuantity());
    }

    /**
     * Once recorded as sent, a later capture does not send it again.
     */
    public function testDoesNotSendTheSingleReservedLineTwice(): void
    {
        $order = $this->buildOrder(
            $this->ticketTotals() + ['first_capture_flag' => false],
            true,
            [Config::QLIROONE_ADDITIONAL_INFO_CAPTURED_UNITS => ['DSC_10' => 1]]
        );

        self::assertSame(['8:SKU-B'], $this->references($this->handler->handle([$this->product('8:SKU-B', 1, 152.10)], $order)));
    }

    /**
     * Nothing recorded on a later capture means the earlier one ran on a version that sent the line
     * with the first capture. It is not sent again, and it is handed to the payment to count.
     */
    public function testTakesTheLineAsSentWhenAnEarlierVersionCapturedFirst(): void
    {
        $order = $this->buildOrder($this->ticketTotals() + ['first_capture_flag' => false]);

        $lines = $this->handler->handle([$this->product('8:SKU-B', 3, 152.10)], $order);

        self::assertSame(['8:SKU-B'], $this->references($lines));
        self::assertSame(['DSC_10' => 1], $order->getData(AppliedRulesHandler::SENT_BEFORE_RECORDING));
    }

    /**
     * The last capture takes what is left, even when that turns it negative: leaving the discount
     * out would charge the customer more than the order.
     */
    public function testTheLastCaptureTakesTheSingleLineWhateverItsTotal(): void
    {
        $order = $this->buildOrder($this->ticketTotals());

        $lines = $this->handler->handle([$this->product('7:SKU-A', 1, 152.10)], $order);

        self::assertSame(['7:SKU-A', 'DSC_10'], $this->references($lines));
    }

    /**
     * A new order: the discount of each product line goes with the units captured, at the price
     * Qliro reserved, and the shipping discount with the first capture.
     */
    public function testAFirstPartialCaptureTakesTheDiscountOfItsOwnUnits(): void
    {
        $order = $this->buildOrder(
            ['first_capture_flag' => true, AppliedRulesHandler::FINAL_CAPTURE_FLAG => false],
            true,
            [Config::QLIROONE_ADDITIONAL_INFO_RESERVED_DISCOUNT => $this->reservation()]
        );

        $lines = $this->handler->handle([$this->product('5:SKU', 1, 199.00), $this->shipping(59.00)], $order);

        self::assertSame(['5:SKU', 'flatrate_flatrate', 'DSC_5:SKU', 'DSC_SHIPPING'], $this->references($lines));
        self::assertSame(1.0, $lines[2]->getQuantity());
        self::assertSame(-33.33, $lines[2]->getPricePerItemIncVat());
        self::assertSame(-26.66, $lines[2]->getPricePerItemExVat());
        self::assertSame(214.67, round($this->total($lines), 2));
    }

    /**
     * A new order shipped after an invoice that captured nothing is not flagged as its first
     * capture, and still takes its order level lines: only an order without a recorded
     * reservation can have been captured by an earlier release.
     */
    public function testANewOrderNeverTakesItsLinesAsSentByAnEarlierRelease(): void
    {
        $order = $this->buildOrder(
            ['first_capture_flag' => false, AppliedRulesHandler::FINAL_CAPTURE_FLAG => false],
            true,
            [Config::QLIROONE_ADDITIONAL_INFO_RESERVED_DISCOUNT => $this->reservation()]
        );

        $lines = $this->handler->handle([$this->product('5:SKU', 1, 199.00), $this->shipping(59.00)], $order);

        self::assertSame(['5:SKU', 'flatrate_flatrate', 'DSC_5:SKU', 'DSC_SHIPPING'], $this->references($lines));
        self::assertNull($order->getData(AppliedRulesHandler::SENT_BEFORE_RECORDING));
    }

    /**
     * The capture of the last units of a line takes the units an öre more.
     */
    public function testTheLastUnitsOfALineTakeTheUnitsAnOreMore(): void
    {
        $order = $this->buildOrder(
            ['first_capture_flag' => false, AppliedRulesHandler::FINAL_CAPTURE_FLAG => true],
            true,
            [
                Config::QLIROONE_ADDITIONAL_INFO_RESERVED_DISCOUNT => $this->reservation(),
                Config::QLIROONE_ADDITIONAL_INFO_CAPTURED_UNITS => ['5:SKU' => 1, 'DSC_5:SKU' => 1, 'DSC_SHIPPING' => 1],
            ]
        );

        $lines = $this->handler->handle([$this->product('5:SKU', 2, 199.00)], $order);

        self::assertSame(['5:SKU', 'DSC_5:SKU', 'DSC_5:SKU_R'], $this->references($lines));
        self::assertSame(1.0, $lines[1]->getQuantity());
        self::assertSame(1.0, $lines[2]->getQuantity());
        self::assertSame(-33.34, $lines[2]->getPricePerItemIncVat());
        self::assertSame(round(2 * 199.00 - 33.33 - 33.34, 2), round($this->total($lines), 2));
    }

    /**
     * Three units at 10.00 with 29.99 off, captured one at a time: no capture turns negative, and
     * together they take the whole discount.
     */
    public function testNoSingleUnitCaptureTurnsNegative(): void
    {
        $reservation = [
            'products' => ['5:SKU' => 3],
            'discounts' => [
                'DSC_5:SKU' => ['inc' => -9.99, 'ex' => -7.99, 'vat' => 25.0, 'quantity' => 1, 'scope' => DiscountLines::SCOPE_LINE],
                'DSC_5:SKU_R' => ['inc' => -10.0, 'ex' => -8.0, 'vat' => 25.0, 'quantity' => 2, 'scope' => DiscountLines::SCOPE_LINE],
            ],
        ];
        $captured = [];
        $sum = 0.0;

        for ($capture = 1; $capture <= 3; $capture++) {
            $order = $this->buildOrder(
                ['first_capture_flag' => $capture === 1, AppliedRulesHandler::FINAL_CAPTURE_FLAG => $capture === 3],
                true,
                [
                    Config::QLIROONE_ADDITIONAL_INFO_RESERVED_DISCOUNT => $reservation,
                    Config::QLIROONE_ADDITIONAL_INFO_CAPTURED_UNITS => $captured,
                ]
            );

            $lines = $this->handler->handle([$this->product('5:SKU', 1, 10.00)], $order);
            $total = round($this->total($lines), 2);

            self::assertGreaterThanOrEqual(0.0, $total);
            $sum += $total;

            foreach ($lines as $line) {
                $reference = $line->getMerchantReference();
                $captured[$reference] = ($captured[$reference] ?? 0) + (int)$line->getQuantity();
            }
        }

        self::assertSame(0.01, round($sum, 2));
        self::assertSame(['5:SKU' => 3, 'DSC_5:SKU' => 1, 'DSC_5:SKU_R' => 2], $captured);
    }

    /**
     * An invoice for the whole order takes every discount line, the full discount as before.
     */
    public function testAWholeOrderCaptureTakesTheWholeDiscount(): void
    {
        $order = $this->buildOrder(
            ['first_capture_flag' => true, AppliedRulesHandler::FINAL_CAPTURE_FLAG => true],
            true,
            [Config::QLIROONE_ADDITIONAL_INFO_RESERVED_DISCOUNT => $this->reservation()]
        );

        $lines = $this->handler->handle([$this->product('5:SKU', 3, 199.00), $this->shipping(59.00)], $order);

        self::assertSame(
            ['5:SKU', 'flatrate_flatrate', 'DSC_5:SKU', 'DSC_5:SKU_R', 'DSC_SHIPPING'],
            $this->references($lines)
        );
        self::assertSame(round(3 * 199.00 + 59.00 - 100.00 - 10.00, 2), round($this->total($lines), 2));
    }

    /**
     * Totals of the ticket's order, prices including VAT with the VAT part stated.
     *
     * @return array<string, string>
     */
    private function ticketTotals(): array
    {
        return [
            'discount_amount' => '-319.5000',
            'discount_tax_compensation_amount' => '63.9000',
        ];
    }

    /**
     * A product of three units at 199.00 with 100.00 off, and 10.00 off the shipping, as the
     * placement records it from the Qliro order.
     */
    private function reservation(): array
    {
        $line = static fn(float $inc, float $ex, int $quantity, string $scope): array => [
            'inc' => $inc, 'ex' => $ex, 'vat' => 25.0, 'quantity' => $quantity, 'scope' => $scope,
        ];

        return [
            'products' => ['5:SKU' => 3],
            'discounts' => [
                'DSC_5:SKU' => $line(-33.33, -26.66, 2, DiscountLines::SCOPE_LINE),
                'DSC_5:SKU_R' => $line(-33.34, -26.67, 1, DiscountLines::SCOPE_LINE),
                'DSC_SHIPPING' => $line(-10.0, -8.0, 1, DiscountLines::SCOPE_ORDER),
            ],
        ];
    }

    private function product(string $reference, int $quantity, float $price): Item
    {
        return (new Item())
            ->setMerchantReference($reference)
            ->setType(QliroOrderItemInterface::TYPE_PRODUCT)
            ->setQuantity($quantity)
            ->setPricePerItemIncVat($price)
            ->setPricePerItemExVat(round($price / 1.25, 2));
    }

    private function shipping(float $price): Item
    {
        return (new Item())
            ->setMerchantReference('flatrate_flatrate')
            ->setType(QliroOrderItemInterface::TYPE_SHIPPING)
            ->setQuantity(1)
            ->setPricePerItemIncVat($price)
            ->setPricePerItemExVat(round($price / 1.25, 2));
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
     * @param QliroOrderItemInterface[] $lines
     */
    private function total(array $lines): float
    {
        $total = 0.0;

        foreach ($lines as $line) {
            $total += $line->getPricePerItemIncVat() * $line->getQuantity();
        }

        return $total;
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
            $resolver,
            new DiscountLines($itemFactory, $qliroHelper, $resolver, new LineVatRate()),
            $this->createMock(LogManager::class)
        );
    }

    /**
     * @param array<string, string> $totals
     */
    private function buildDiscountLine(
        array $totals,
        bool $reservationCarriesDiscountVat = true
    ): QliroOrderItemInterface {
        $orderItems = $this->handler->handle([], $this->buildOrder($totals, $reservationCarriesDiscountVat));

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
    private function buildOrder(
        array $totals,
        bool $reservationCarriesDiscountVat = true,
        array $additionalInformation = []
    ): Order&MockObject {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            static fn($key = null) => $additionalInformation[$key]
                ?? ($key === Config::QLIROONE_ADDITIONAL_INFO_DISCOUNT_CARRIES_VAT ? $reservationCarriesDiscountVat : null)
        );

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getAllItems', 'getPayment'])
            ->getMock();

        $order->method('getAllItems')->willReturn([new DataObject(['tax_percent' => 25.0])]);
        $order->method('getPayment')->willReturn($payment);
        $order->setData($totals + [
            'first_capture_flag' => true,
            AppliedRulesHandler::FINAL_CAPTURE_FLAG => true,
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
