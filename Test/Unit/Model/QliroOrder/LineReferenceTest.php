<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\LineReference;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\LineReference
 */
class LineReferenceTest extends TestCase
{
    private LineReference $lineReference;

    protected function setUp(): void
    {
        $this->lineReference = new LineReference();
    }

    /**
     * The cart item id goes in front of the sku, which is the format the module used before 1.7.0
     * and the one the metadata key has carried all along.
     */
    public function testTheItemIdGoesInFrontOfTheSku(): void
    {
        self::assertSame('519:Kanalplast', $this->lineReference->forItem(519, 'Kanalplast'));
    }

    /**
     * Nothing to qualify the sku with means the sku stands alone, rather than a reference that
     * starts with the separator and carries an empty id.
     */
    public function testWithoutAnItemIdTheSkuStandsAlone(): void
    {
        self::assertSame('Kanalplast', $this->lineReference->forItem(null, 'Kanalplast'));
        self::assertSame('Kanalplast', $this->lineReference->forItem('', 'Kanalplast'));
    }

    /**
     * A sku may hold a separator of its own, so the id is everything before the first one.
     */
    public function testASkuHoldingASeparatorIsReadBackWhole(): void
    {
        $reference = $this->lineReference->forItem(519, 'AB:12:34');

        self::assertSame('519:AB:12:34', $reference);
        self::assertSame('519', $this->lineReference->itemIdOf($reference));
        self::assertSame('AB:12:34', $this->lineReference->skuOf($reference));
    }

    /**
     * A bare sku that holds a separator is still a sku whole: only digits in front of the first
     * one are a cart item id, so `AB:12` is not read as item AB of sku 12.
     */
    public function testABareSkuHoldingASeparatorIsNotReadAsAnId(): void
    {
        self::assertNull($this->lineReference->itemIdOf('AB:12'));
        self::assertSame('AB:12', $this->lineReference->skuOf('AB:12'));
    }

    /**
     * Such a sku also survives the downgrade a capture of an older order goes through.
     */
    public function testABareSkuHoldingASeparatorSurvivesTheDowngrade(): void
    {
        $lines = $this->lineReference->alignWithReservation(
            [$this->buildLine('AB:12', QliroOrderItemInterface::TYPE_PRODUCT)],
            $this->buildOrder(false)
        );

        self::assertSame('AB:12', $lines[0]->getMerchantReference());
    }

    /**
     * A reference from before this release carries the sku alone and has no id to read.
     */
    public function testAReferenceWithoutAnIdIsAllSku(): void
    {
        self::assertNull($this->lineReference->itemIdOf('Kanalplast'));
        self::assertSame('Kanalplast', $this->lineReference->skuOf('Kanalplast'));
    }

    /**
     * The stamp says the reservation was built with the id in its references.
     */
    public function testAStampedOrderReservedTheReferencesWithTheItemId(): void
    {
        self::assertTrue($this->lineReference->reservationCarriesItemId($this->buildOrder(true)));
    }

    /**
     * An order placed before this release carries no stamp, and Qliro refuses a capture whose
     * lines disagree with the reservation, so its lines have to keep the bare sku.
     */
    public function testAnOrderFromBeforeTheStampKeepsTheBareSku(): void
    {
        $lines = $this->lineReference->alignWithReservation(
            [$this->buildLine('519:Kanalplast', QliroOrderItemInterface::TYPE_PRODUCT)],
            $this->buildOrder(false)
        );

        self::assertSame('Kanalplast', $lines[0]->getMerchantReference());
    }

    /**
     * A stamped order keeps the references this version builds.
     */
    public function testAStampedOrderKeepsItsReferences(): void
    {
        $lines = $this->lineReference->alignWithReservation(
            [$this->buildLine('519:Kanalplast', QliroOrderItemInterface::TYPE_PRODUCT)],
            $this->buildOrder(true)
        );

        self::assertSame('519:Kanalplast', $lines[0]->getMerchantReference());
    }

    /**
     * Only a product line carries a sku. The shipping, fee and discount lines name themselves and
     * would lose their reference to the separator in a shipping method code.
     */
    public function testOnlyProductLinesAreAligned(): void
    {
        $lines = $this->lineReference->alignWithReservation(
            [
                $this->buildLine('flatrate:flatrate', QliroOrderItemInterface::TYPE_SHIPPING),
                $this->buildLine('qliro_fee', QliroOrderItemInterface::TYPE_FEE),
                $this->buildLine('rule:7', QliroOrderItemInterface::TYPE_DISCOUNT),
            ],
            $this->buildOrder(false)
        );

        self::assertSame('flatrate:flatrate', $lines[0]->getMerchantReference());
        self::assertSame('qliro_fee', $lines[1]->getMerchantReference());
        self::assertSame('rule:7', $lines[2]->getMerchantReference());
    }

    /**
     * An order whose payment cannot be read is treated as one from before the stamp, which is the
     * shape every reservation older than this release holds.
     */
    public function testAnOrderWithoutAPaymentIsTreatedAsUnstamped(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn(null);

        self::assertFalse($this->lineReference->reservationCarriesItemId($order));
    }

    private function buildOrder(bool $stamped): Order
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')
            ->willReturnCallback(
                static fn($key = null) => $key === Config::QLIROONE_ADDITIONAL_INFO_LINE_REFERENCE_CARRIES_ITEM_ID
                    ? $stamped
                    : null
            );

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }

    private function buildLine(string $reference, string $type): QliroOrderItemInterface
    {
        $line = new Item();
        $line->setMerchantReference($reference);
        $line->setType($type);

        return $line;
    }
}
