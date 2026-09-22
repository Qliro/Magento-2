<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder;

use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\RoundingAdjustment;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\RoundingAdjustment
 */
class RoundingAdjustmentTest extends TestCase
{
    private RoundingAdjustment $roundingAdjustment;

    protected function setUp(): void
    {
        $itemFactory = $this->createMock(QliroOrderItemInterfaceFactory::class);
        $itemFactory->method('create')->willReturnCallback(static fn(): Item => new Item());

        $this->roundingAdjustment = new RoundingAdjustment($itemFactory);
    }

    /**
     * Skyltexperten's cart of 50 Kanalplast, Qliro order 2KjUP3. The unit price is 45.94 ex VAT,
     * 57.425 inc, so the store charges 2871.25 and the line, which can only state two decimals,
     * says 50 x 57.43 = 2871.50. The 25 öre in between is what stopped the checkout.
     */
    public function testStatesTheOreTheLinesLeaveOpen(): void
    {
        $adjustment = $this->roundingAdjustment->resolve([$this->line(50, 57.43)], 2871.25);

        self::assertNotNull($adjustment);
        self::assertSame(-0.25, $adjustment['incVat']);
        self::assertSame(-0.2, $adjustment['exVat']);
        self::assertSame(25.0, $adjustment['vatRate']);
    }

    /**
     * The same store's Qliro order B4MPAQ, three lines where only the last one drifts:
     * 25 x 24.6875 is 617.19 in the store and 617.25 on the line.
     */
    public function testMeasuresTheDriftAcrossEveryLine(): void
    {
        $adjustment = $this->roundingAdjustment->resolve(
            [$this->line(50, 27.5), $this->line(25, 25.1), $this->line(25, 24.69)],
            2619.69
        );

        self::assertNotNull($adjustment);
        self::assertSame(-0.06, $adjustment['incVat']);
        self::assertSame(-0.05, $adjustment['exVat']);
    }

    /**
     * A unit price that rounds down leaves the store charging more than the lines say, and an
     * amount put on the order is a fee. Qliro reads a discount as an amount taken off it.
     */
    public function testAnAmountTheStoreChargesOnTopGoesOutAsAFee(): void
    {
        $adjustment = $this->roundingAdjustment->resolve([$this->line(25, 24.68)], 617.06);
        $item = $this->roundingAdjustment->buildItem($adjustment);

        self::assertSame(0.06, $adjustment['incVat']);
        self::assertSame(QliroOrderItemInterface::TYPE_FEE, $item->getType());
    }

    /**
     * The line the amount goes out on, which is also the line a capture has to reproduce.
     */
    public function testBuildsOneDiscountLineOfQuantityOne(): void
    {
        $item = $this->roundingAdjustment->buildItem(
            $this->roundingAdjustment->resolve([$this->line(50, 57.43)], 2871.25)
        );

        self::assertSame(QliroOrderItemInterface::TYPE_DISCOUNT, $item->getType());
        self::assertSame('ROUNDING', $item->getMerchantReference());
        self::assertSame(1.0, $item->getQuantity());
        self::assertSame(-0.25, $item->getPricePerItemIncVat());
        self::assertSame(-0.2, $item->getPricePerItemExVat());
        self::assertSame(25.0, $item->getVatRate());
    }

    /**
     * Lines that already add up to the store's total need no line of their own.
     */
    public function testAddsNothingWhenTheLinesAlreadyAddUp(): void
    {
        self::assertNull($this->roundingAdjustment->resolve([$this->line(3, 20.0)], 60.0));
    }

    /**
     * A discount line counts towards the total the same way a product line does.
     */
    public function testCountsEveryLineTowardsTheTotal(): void
    {
        $discount = (new Item())
            ->setType(QliroOrderItemInterface::TYPE_DISCOUNT)
            ->setQuantity(1)
            ->setPricePerItemIncVat(-10.0);

        self::assertNull($this->roundingAdjustment->resolve([$this->line(3, 20.0), $discount], 50.0));
    }

    /**
     * Rounding a unit price moves it by less than half an öre, so a cart of 50 units cannot
     * honestly drift by more than 25 öre, plus the one the store's own total is rounded by.
     */
    public function testAbsorbsUpToHalfAnOrePerUnitAndOneOreOnTop(): void
    {
        self::assertNotNull($this->roundingAdjustment->resolve([$this->line(50, 57.43)], 2871.24));
        self::assertNull($this->roundingAdjustment->resolve([$this->line(50, 57.43)], 2871.23));
    }

    /**
     * A disagreement larger than rounding is not one to absorb: the checkout guard exists to
     * catch it, and a line that hid it would let the customer pay a total nothing checked.
     */
    public function testLeavesARealDisagreementAlone(): void
    {
        self::assertNull($this->roundingAdjustment->resolve([$this->line(1, 100.0)], 90.0));
    }

    /**
     * The drift is fractions of an öre from every line at once, and the largest line is the one
     * that contributed most of it.
     */
    public function testTakesTheVatRateOfTheLargestProductLine(): void
    {
        $reduced = $this->line(10, 5.0, 12.0);
        $standard = $this->line(10, 50.0, 25.0);

        $adjustment = $this->roundingAdjustment->resolve([$reduced, $standard], 549.97);

        self::assertSame(25.0, $adjustment['vatRate']);
    }

    /**
     * What the capture replays is the line the reservation holds, read back off it.
     */
    public function testReadsItsOwnLineBackOffAnOrder(): void
    {
        $item = $this->roundingAdjustment->buildItem(
            $this->roundingAdjustment->resolve([$this->line(50, 57.43)], 2871.25)
        );

        $found = $this->roundingAdjustment->findIn([$this->line(50, 57.43), $item]);

        self::assertSame($item, $found);
        self::assertSame(
            ['incVat' => -0.25, 'exVat' => -0.2, 'vatRate' => 25.0, 'description' => $item->getDescription()],
            $this->roundingAdjustment->toArray($found)
        );
    }

    /**
     * A product may be called ROUNDING: an order reserved before 1.7.42 carries the bare sku as
     * its line reference, and stamping that product's price would put a bogus line on the capture.
     */
    public function testDoesNotTakeAProductLineForItsOwn(): void
    {
        $product = (new Item())
            ->setMerchantReference(RoundingAdjustment::MERCHANT_REFERENCE)
            ->setType(QliroOrderItemInterface::TYPE_PRODUCT)
            ->setQuantity(2)
            ->setPricePerItemIncVat(125.0)
            ->setVatRate(25.0);

        self::assertNull($this->roundingAdjustment->findIn([$product]));
    }

    /**
     * What the caller needs to say in the log when the difference is one this class will not
     * absorb: the difference itself and the largest one rounding could have produced.
     */
    public function testStatesTheDifferenceAndTheBoundItIsMeasuredAgainst(): void
    {
        $lines = [$this->line(50, 57.43)];

        self::assertSame(-0.25, $this->roundingAdjustment->difference($lines, 2871.25));
        self::assertSame(0.0, $this->roundingAdjustment->difference($lines, 2871.5));
        self::assertSame(0.26, $this->roundingAdjustment->maxDifference($lines));
    }

    /**
     * An order placed before the line existed carries no stamp, and a stamp of zero says the
     * cart added up: neither is a line to put on a capture.
     */
    public function testRefusesAStampThatIsNotAnAdjustment(): void
    {
        self::assertFalse($this->roundingAdjustment->isAdjustment(null));
        self::assertFalse($this->roundingAdjustment->isAdjustment('-0.25'));
        self::assertFalse($this->roundingAdjustment->isAdjustment(['incVat' => -0.25]));
        self::assertFalse(
            $this->roundingAdjustment->isAdjustment(['incVat' => 0.0, 'exVat' => 0.0, 'vatRate' => 25.0])
        );
        self::assertTrue(
            $this->roundingAdjustment->isAdjustment(['incVat' => -0.25, 'exVat' => -0.2, 'vatRate' => 25.0])
        );
    }

    /**
     * A cart whose lines are all free has no largest line to read the rate off, and the öre is
     * not VAT free just because the goods it came from were given away.
     */
    public function testFallsBackToTheFirstProductLineForTheRate(): void
    {
        $free = $this->line(1, 0.0, 25.0);
        $discount = (new Item())
            ->setType(QliroOrderItemInterface::TYPE_DISCOUNT)
            ->setQuantity(1)
            ->setPricePerItemIncVat(-0.0);

        $adjustment = $this->roundingAdjustment->resolve([$free, $discount], 0.01);

        self::assertSame(25.0, $adjustment['vatRate']);
    }

    /**
     * There is nothing to measure against an empty cart.
     */
    public function testAddsNothingToAnEmptySetOfLines(): void
    {
        self::assertNull($this->roundingAdjustment->resolve([], 0.0));
    }

    /**
     * @param float $quantity
     * @param float $priceIncVat
     * @param float $vatRate
     * @return QliroOrderItemInterface
     */
    private function line(float $quantity, float $priceIncVat, float $vatRate = 25.0): QliroOrderItemInterface
    {
        return (new Item())
            ->setType(QliroOrderItemInterface::TYPE_PRODUCT)
            ->setQuantity($quantity)
            ->setPricePerItemIncVat($priceIncVat)
            ->setVatRate($vatRate);
    }
}
