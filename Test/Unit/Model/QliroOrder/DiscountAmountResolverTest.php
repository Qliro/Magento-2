<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder;

use Magento\Framework\DataObject;
use Magento\Tax\Model\Config as TaxConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\DiscountAmountResolver;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\DiscountAmountResolver
 */
class DiscountAmountResolverTest extends TestCase
{
    private LogManager&MockObject $logManager;

    protected function setUp(): void
    {
        $this->logManager = $this->createMock(LogManager::class);
    }

    /**
     * Prices include tax and tax follows the discount, the Nordic setup. Magento states the VAT
     * part of the discount, so it is simply taken off.
     */
    public function testTakesTheVatPartFromMagentoWhenItIsStated(): void
    {
        $resolver = $this->buildResolver(priceIncludesTax: true, applyTaxAfterDiscount: true);

        $totals = $this->buildTotals(discount: -0.72, compensation: 0.14, subtotal: 14.4, subtotalInclTax: 18.0);

        self::assertSame([0.72, 0.58], $resolver->resolve($totals, [25.0], 1));
    }

    /**
     * Prices exclude tax and tax follows the discount, Magento's own default. The discount amount
     * is ex VAT and the VAT drops with it, so the line has to carry that VAT back. Sending
     * 10.00 / 10.00 here charges the customer 2.50 more than Magento's grand total.
     */
    public function testGrossesUpTheDiscountWhenMagentoStatesNoVatPart(): void
    {
        $resolver = $this->buildResolver(priceIncludesTax: false, applyTaxAfterDiscount: true);

        // 100.00 of goods at 25 percent, 10.00 off, so Magento charges tax on 90.00
        $totals = $this->buildTotals(
            discount: -10.0,
            compensation: 0.0,
            subtotal: 100.0,
            subtotalInclTax: 125.0,
            taxAmount: 22.5
        );

        self::assertSame([12.5, 10.0], $resolver->resolve($totals, [25.0], 1));
    }

    /**
     * The discount line closes the gap between the lines we send, which are priced before the
     * discount, and the total Magento charges.
     */
    public function testTheDiscountLineBringsTheTotalBackToMagentosGrandTotal(): void
    {
        $resolver = $this->buildResolver(priceIncludesTax: false, applyTaxAfterDiscount: true);

        $totals = $this->buildTotals(
            discount: -10.0,
            compensation: 0.0,
            subtotal: 100.0,
            subtotalInclTax: 125.0,
            shipping: 49.0,
            shippingInclTax: 61.25,
            taxAmount: 34.75
        );

        [$inclVat] = $resolver->resolve($totals, [25.0], 1);

        $grandTotal = 100.0 + 49.0 + 34.75 - 10.0;

        self::assertSame($grandTotal, round(125.0 + 61.25 - $inclVat, 2));
    }

    /**
     * A rule that discounts the shipping too takes VAT off the shipping, and the shipping line is
     * sent at its undiscounted price, so that VAT belongs on the discount line as well. The cart's
     * own rate cannot produce this figure when shipping is taxed at another rate.
     */
    public function testCarriesTheVatOfADiscountedShippingLine(): void
    {
        $resolver = $this->buildResolver(priceIncludesTax: false, applyTaxAfterDiscount: true);

        // 100.00 of goods at 25 percent and 50.00 of shipping at 6 percent, 10 percent off both
        $totals = $this->buildTotals(
            discount: -15.0,
            compensation: 0.0,
            subtotal: 100.0,
            subtotalInclTax: 125.0,
            shipping: 50.0,
            shippingInclTax: 53.0,
            taxAmount: 25.2
        );

        self::assertSame([17.8, 15.0], $resolver->resolve($totals, [25.0], 1));
    }

    /**
     * A discount that lands entirely on the higher taxed half of a mixed rate cart carries that
     * higher rate, well above the cart's average. The rates the lines carry are what tells this
     * apart from totals that no longer describe the discount.
     */
    public function testAllowsADiscountTakenAtTheHighestRateInAMixedRateCart(): void
    {
        $resolver = $this->buildResolver(priceIncludesTax: false, applyTaxAfterDiscount: true);

        // 100.00 at 25 percent and 100.00 at 6 percent, the whole 10.00 off the 25 percent half
        $totals = $this->buildTotals(
            discount: -10.0,
            compensation: 0.0,
            subtotal: 200.0,
            subtotalInclTax: 231.0,
            taxAmount: 28.5
        );

        self::assertSame([12.5, 10.0], $resolver->resolve($totals, [25.0, 6.0], 1));
    }

    /**
     * The same cart with no rates on the lines falls back to the ceiling the totals imply, which
     * is the cart's average. It undercharges rather than overcharges, and it says so in the log.
     */
    public function testFallsBackToTheAverageRateCeilingWhenTheLinesCarryNoRates(): void
    {
        $this->logManager->expects(self::once())->method('warning');

        $resolver = $this->buildResolver(priceIncludesTax: false, applyTaxAfterDiscount: true);

        $totals = $this->buildTotals(
            discount: -10.0,
            compensation: 0.0,
            subtotal: 200.0,
            subtotalInclTax: 231.0,
            taxAmount: 28.5
        );

        self::assertSame([10.0, 10.0], $resolver->resolve($totals, [], 1));
    }

    /**
     * A single rate cart whose items carry no tax percent still gets its VAT, because the rate the
     * subtotals imply is the real one there.
     */
    public function testUsesTheRateTheSubtotalsImplyWhenTheItemsCarryNone(): void
    {
        $this->logManager->expects(self::never())->method('warning');

        $resolver = $this->buildResolver(priceIncludesTax: false, applyTaxAfterDiscount: true);

        $totals = $this->buildTotals(
            discount: -10.0,
            compensation: 0.0,
            subtotal: 100.0,
            subtotalInclTax: 125.0,
            taxAmount: 22.5
        );

        self::assertSame([12.5, 10.0], $resolver->resolve($totals, [0.0], 1));
    }

    /**
     * Another total collector taxing something the subtotals do not account for is how this
     * difference stops describing the discount. Sending it would charge the customer more than
     * Magento asked, so the VAT is dropped and the store is diagnosable from the log.
     */
    public function testDiscardsAVatBeyondWhatTheCartsRatesAllow(): void
    {
        $this->logManager->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('Discount VAT discarded'), self::anything());

        $resolver = $this->buildResolver(priceIncludesTax: false, applyTaxAfterDiscount: true);

        $totals = $this->buildTotals(
            discount: -10.0,
            compensation: 0.0,
            subtotal: 100.0,
            subtotalInclTax: 125.0,
            taxAmount: 0.0
        );

        self::assertSame([10.0, 10.0], $resolver->resolve($totals, [25.0], 1));
    }

    /**
     * @dataProvider taxBeforeDiscountProvider
     */
    public function testSendsNoVatWhenTaxWasCalculatedBeforeTheDiscount(bool $priceIncludesTax): void
    {
        $resolver = $this->buildResolver($priceIncludesTax, applyTaxAfterDiscount: false);

        $totals = $this->buildTotals(
            discount: -10.0,
            compensation: 0.0,
            subtotal: 100.0,
            subtotalInclTax: 125.0,
            taxAmount: 25.0
        );

        self::assertSame([10.0, 10.0], $resolver->resolve($totals, [25.0], 1));
    }

    /**
     * @return array<string, array{0: bool}>
     */
    public static function taxBeforeDiscountProvider(): array
    {
        return [
            'prices include tax' => [true],
            'prices exclude tax' => [false],
        ];
    }

    /**
     * A cart without VAT has no VAT to put on the discount either, and nothing worth logging.
     */
    public function testKeepsTheAmountWhenTheCartCarriesNoVat(): void
    {
        $this->logManager->expects(self::never())->method('warning');

        $resolver = $this->buildResolver(priceIncludesTax: false, applyTaxAfterDiscount: true);

        $totals = $this->buildTotals(
            discount: -10.0,
            compensation: 0.0,
            subtotal: 100.0,
            subtotalInclTax: 100.0
        );

        self::assertSame([10.0, 10.0], $resolver->resolve($totals, [0.0], 1));
    }

    /**
     * Nonsense compensation, larger than the discount itself, must not produce a negative ex VAT
     * amount. It falls through to the configuration instead.
     */
    public function testIgnoresCompensationLargerThanTheDiscount(): void
    {
        $resolver = $this->buildResolver(priceIncludesTax: true, applyTaxAfterDiscount: true);

        $totals = $this->buildTotals(discount: -10.0, compensation: 12.0, subtotal: 100.0, subtotalInclTax: 125.0);

        self::assertSame([10.0, 10.0], $resolver->resolve($totals, [25.0], 1));
    }

    /**
     * Totals that have not been collected leave the amount alone rather than inventing VAT.
     */
    public function testKeepsTheAmountWhenTheTotalsAreMissing(): void
    {
        $this->logManager->expects(self::never())->method('warning');

        $resolver = $this->buildResolver(priceIncludesTax: false, applyTaxAfterDiscount: true);

        self::assertSame(
            [10.0, 10.0],
            $resolver->resolve(new DataObject(['discount_amount' => -10.0]), [25.0], 1)
        );
    }

    /**
     * The rate has to reconcile the two amounts in the payload back to the öre, that is what the
     * fractional rate is for. Qliro rejects anything with more than two decimals.
     */
    public function testTheVatRateReconcilesTheAmountsThatAreSent(): void
    {
        $resolver = $this->buildResolver(priceIncludesTax: true, applyTaxAfterDiscount: true);

        self::assertSame(24.14, $resolver->getVatRate(0.72, 0.58));
        self::assertSame(0.72, round(0.58 * (1 + 24.14 / 100), 2));
    }

    /**
     * Equal amounts are a line without VAT, and an ex VAT amount of nothing cannot carry a rate.
     */
    public function testReportsNoRateWhenThereIsNoVatToDescribe(): void
    {
        $resolver = $this->buildResolver(priceIncludesTax: true, applyTaxAfterDiscount: true);

        self::assertSame(0.0, $resolver->getVatRate(10.0, 10.0));
        self::assertSame(0.0, $resolver->getVatRate(10.0, 0.0));
    }

    private function buildResolver(bool $priceIncludesTax, bool $applyTaxAfterDiscount): DiscountAmountResolver
    {
        $taxConfig = $this->createMock(TaxConfig::class);
        $taxConfig->method('priceIncludesTax')->willReturn($priceIncludesTax);
        $taxConfig->method('applyTaxAfterDiscount')->willReturn($applyTaxAfterDiscount);

        return new DiscountAmountResolver($taxConfig, $this->logManager);
    }

    /**
     * Magento keeps the discount negative and the tax compensation positive, on the quote address
     * and on the order alike.
     */
    private function buildTotals(
        float $discount,
        float $compensation,
        float $subtotal,
        float $subtotalInclTax,
        float $shipping = 0.0,
        float $shippingInclTax = 0.0,
        float $taxAmount = 0.0
    ): DataObject {
        return new DataObject([
            'discount_amount' => $discount,
            'discount_tax_compensation_amount' => $compensation,
            'subtotal' => $subtotal,
            'subtotal_incl_tax' => $subtotalInclTax,
            'shipping_amount' => $shipping,
            'shipping_incl_tax' => $shippingInclTax,
            'tax_amount' => $taxAmount,
        ]);
    }
}
