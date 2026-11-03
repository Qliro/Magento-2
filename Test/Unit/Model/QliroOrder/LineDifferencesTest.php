<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder;

use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\LineDifferences;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\LineDifferences
 */
class LineDifferencesTest extends TestCase
{
    public function testMatchingLinesHaveNoDifferences(): void
    {
        $lines = [$this->line('518:SKU-A', 2), $this->line('519:SKU-B', 1)];

        self::assertSame([], (new LineDifferences())->between($lines, $lines));
    }

    public function testShippingAndFeeLinesAreLeftOut(): void
    {
        $cart = [$this->line('518:SKU-A', 1), $this->line('flatrate_flatrate', 1, 49.0, QliroOrderItemInterface::TYPE_SHIPPING)];
        $qliro = [
            $this->line('518:SKU-A', 1),
            $this->line('freeshipping_freeshipping', 1, 0.0, QliroOrderItemInterface::TYPE_SHIPPING),
            $this->line('INVOICE_FEE', 1, 29.0, QliroOrderItemInterface::TYPE_FEE),
        ];

        self::assertSame([], (new LineDifferences())->between($cart, $qliro));
    }

    public function testALineMissingFromTheCartIsReportedWithBothQuantities(): void
    {
        $differences = (new LineDifferences())->between(
            [$this->line('518:SKU-A', 1)],
            [$this->line('518:SKU-A', 1), $this->line('519:SKU-B', 3)]
        );

        self::assertSame([[
            'merchant_reference' => '519:SKU-B',
            'reason' => LineDifferences::REASON_NOT_IN_CART,
            'cart_quantity' => null,
            'qliro_quantity' => 3.0,
            'cart_price' => null,
            'qliro_price' => ['inc_vat' => 125.0, 'ex_vat' => 100.0],
        ]], $differences);
    }

    public function testALineMissingFromTheQliroOrderIsReported(): void
    {
        $differences = (new LineDifferences())->between(
            [$this->line('518:SKU-A', 1), $this->line('519:SKU-B', 1)],
            [$this->line('518:SKU-A', 1)]
        );

        self::assertSame(LineDifferences::REASON_NOT_IN_QLIRO, $differences[0]['reason']);
        self::assertSame(1.0, $differences[0]['cart_quantity']);
        self::assertNull($differences[0]['qliro_quantity']);
    }

    public function testADifferentQuantityIsReported(): void
    {
        $differences = (new LineDifferences())->between([$this->line('518:SKU-A', 1)], [$this->line('518:SKU-A', 2)]);

        self::assertSame(LineDifferences::REASON_QUANTITY, $differences[0]['reason']);
        self::assertSame([1.0, 2.0], [$differences[0]['cart_quantity'], $differences[0]['qliro_quantity']]);
    }

    public function testADifferentPriceIsReported(): void
    {
        $differences = (new LineDifferences())->between(
            [$this->line('518:SKU-A', 1, 100.0)],
            [$this->line('518:SKU-A', 1, 125.0)]
        );

        self::assertSame(LineDifferences::REASON_PRICE, $differences[0]['reason']);
    }

    public function testPricesMatchToTheOre(): void
    {
        $cart = $this->line('518:SKU-A', 1);
        $cart->setPricePerItemExVat(27.200000000000003);
        $qliro = $this->line('518:SKU-A', 1);
        $qliro->setPricePerItemExVat(27.2);

        self::assertSame([], (new LineDifferences())->between([$cart], [$qliro]));
    }

    public function testADifferentTypeIsReported(): void
    {
        $differences = (new LineDifferences())->between(
            [$this->line('DSC_ORDER', 1, -10.0)],
            [$this->line('DSC_ORDER', 1, -10.0, QliroOrderItemInterface::TYPE_DISCOUNT)]
        );

        self::assertSame(LineDifferences::REASON_TYPE, $differences[0]['reason']);
    }

    /**
     * Qliro merges lines sharing a reference, so the two sides cannot be told apart
     */
    public function testARepeatedReferenceIsReportedWithItsSummedQuantity(): void
    {
        $differences = (new LineDifferences())->between(
            [$this->line('SKU-A', 1), $this->line('SKU-A', 1)],
            [$this->line('SKU-A', 2)]
        );

        self::assertSame(LineDifferences::REASON_REPEATED, $differences[0]['reason']);
        self::assertSame([2.0, 2.0], [$differences[0]['cart_quantity'], $differences[0]['qliro_quantity']]);
    }

    public function testANumericReferenceStaysAString(): void
    {
        $differences = (new LineDifferences())->between([], [$this->line('1001', 1)]);

        self::assertSame('1001', $differences[0]['merchant_reference']);
    }

    private function line(
        string $reference,
        float $quantity,
        float $incVat = 125.0,
        string $type = QliroOrderItemInterface::TYPE_PRODUCT
    ): Item {
        $line = new Item();
        $line->setMerchantReference($reference);
        $line->setType($type);
        $line->setQuantity($quantity);
        $line->setPricePerItemIncVat($incVat);
        $line->setPricePerItemExVat($incVat / 1.25);

        return $line;
    }
}
