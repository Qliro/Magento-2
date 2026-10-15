<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder;

use Magento\Framework\DataObject;
use Magento\Quote\Model\Quote;
use Magento\Tax\Model\Config as TaxConfig;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Helper\Data as QliroHelper;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\DiscountAmountResolver;
use Qliro\QliroOne\Model\QliroOrder\DiscountLines;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\LineVatRate;
use Qliro\QliroOne\Model\QliroOrder\RoundingAdjustment;

/**
 * What the placement records of the reservation
 *
 * @see \Qliro\QliroOne\Model\QliroOrder\DiscountLines
 */
class DiscountLinesTest extends TestCase
{
    /**
     * Per line discounts are told apart from order level ones by the product lines they follow.
     */
    public function testRecordsTheReservedLinesWithTheirScope(): void
    {
        $reservation = $this->discountLines()->reservation([
            $this->line('5:SKU', QliroOrderItemInterface::TYPE_PRODUCT, 3, 199.0),
            $this->line('DSC_5:SKU', QliroOrderItemInterface::TYPE_DISCOUNT, 3, -33.33),
            $this->line('DSC_5:SKU_R', QliroOrderItemInterface::TYPE_DISCOUNT, 1, -0.01),
            $this->line('DSC_SHIPPING', QliroOrderItemInterface::TYPE_DISCOUNT, 1, -10.0),
            $this->line('DSC_10', QliroOrderItemInterface::TYPE_DISCOUNT, 1, -5.0),
        ]);

        self::assertSame(['5:SKU' => 3], $reservation['products']);
        self::assertSame(DiscountLines::SCOPE_LINE, $reservation['discounts']['DSC_5:SKU']['scope']);
        self::assertSame(DiscountLines::SCOPE_LINE, $reservation['discounts']['DSC_5:SKU_R']['scope']);
        self::assertSame(DiscountLines::SCOPE_ORDER, $reservation['discounts']['DSC_SHIPPING']['scope']);
        self::assertSame(DiscountLines::SCOPE_ORDER, $reservation['discounts']['DSC_10']['scope']);
        self::assertSame(-33.33, $reservation['discounts']['DSC_5:SKU']['inc']);
        self::assertSame(3, $reservation['discounts']['DSC_5:SKU']['quantity']);
    }

    /**
     * The rounding line has a handler of its own that replays it, recording it here would send it twice.
     */
    public function testLeavesTheRoundingLineOut(): void
    {
        $reservation = $this->discountLines()->reservation([
            $this->line('5:SKU', QliroOrderItemInterface::TYPE_PRODUCT, 3, 199.0),
            $this->line('DSC_5:SKU', QliroOrderItemInterface::TYPE_DISCOUNT, 3, -33.33),
            $this->line(RoundingAdjustment::MERCHANT_REFERENCE, QliroOrderItemInterface::TYPE_DISCOUNT, 1, -0.01),
        ]);

        self::assertSame(['DSC_5:SKU'], array_keys($reservation['discounts']));
    }

    /**
     * Lines that hold none of a discounted cart's discount are not recorded, the capture then
     * sends the one order level line rather than no discount at all.
     */
    public function testRecordsNothingWhenTheLinesMissTheCartDiscount(): void
    {
        $lines = [$this->line('5:SKU', QliroOrderItemInterface::TYPE_PRODUCT, 3, 199.0)];

        self::assertNull($this->discountLines()->reservationFor($lines, $this->quote('-30.0000')));
        self::assertSame(
            ['products' => ['5:SKU' => 3], 'discounts' => []],
            $this->discountLines()->reservationFor($lines, $this->quote('0.0000'))
        );
    }

    private function line(string $reference, string $type, int $quantity, float $price): Item
    {
        return (new Item())
            ->setMerchantReference($reference)
            ->setType($type)
            ->setQuantity($quantity)
            ->setPricePerItemIncVat($price)
            ->setPricePerItemExVat(round($price / 1.25, 2))
            ->setVatRate(25.0);
    }

    private function quote(string $discount): Quote
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getShippingAddress')->willReturn(new DataObject(['discount_amount' => $discount]));

        return $quote;
    }

    private function discountLines(): DiscountLines
    {
        $itemFactory = $this->createMock(QliroOrderItemInterfaceFactory::class);
        $itemFactory->method('create')->willReturnCallback(static fn(): Item => new Item());

        $resolver = new DiscountAmountResolver(
            $this->createMock(TaxConfig::class),
            $this->createMock(LogManager::class),
            new LineVatRate()
        );

        return new DiscountLines($itemFactory, $this->createMock(QliroHelper::class), $resolver, new LineVatRate());
    }
}
