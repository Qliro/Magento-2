<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Admin\CreditMemo;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Item as CreditmemoItem;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\QliroOrder\Admin\CreditMemo\RefundVatSplit;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Admin\CreditMemo\RefundVatSplit
 */
class RefundVatSplitTest extends TestCase
{
    public function testSplitsLinesByTheirOwnRates(): void
    {
        $creditMemo = $this->creditMemo(237.0, [$this->line(25.0, 100.0, 25.0), $this->line(12.0, 100.0, 12.0)]);

        self::assertSame(
            [['vat_rate' => 25.0, 'amount' => 125.0], ['vat_rate' => 12.0, 'amount' => 112.0]],
            (new RefundVatSplit())->forCreditMemo($creditMemo)
        );
    }

    public function testWeighsALineAfterItsDiscount(): void
    {
        $creditMemo = $this->creditMemo(
            162.0,
            [$this->line(25.0, 100.0, 12.5, 62.5), $this->line(12.0, 100.0, 12.0)],
            ['discount' => -62.5]
        );

        self::assertSame(
            [['vat_rate' => 12.0, 'amount' => 112.0], ['vat_rate' => 25.0, 'amount' => 50.0]],
            (new RefundVatSplit())->forCreditMemo($creditMemo)
        );
    }

    public function testRefundsShippingAtTheRateItWasCharged(): void
    {
        $creditMemo = $this->creditMemo(
            162.0,
            [$this->line(12.0, 100.0, 12.0)],
            ['shipping' => 40.0, 'shipping_tax' => 10.0],
            $this->order(40.0, 50.0)
        );

        self::assertSame(
            [['vat_rate' => 12.0, 'amount' => 112.0], ['vat_rate' => 25.0, 'amount' => 50.0]],
            (new RefundVatSplit())->forCreditMemo($creditMemo)
        );
    }

    public function testTakesTheShippingDiscountOffShipping(): void
    {
        $creditMemo = $this->creditMemo(
            137.0,
            [$this->line(12.0, 100.0, 12.0)],
            ['shipping' => 40.0, 'shipping_tax' => 5.0, 'dtc' => 5.0, 'discount' => -25.0],
            $this->order(40.0, 50.0)
        );

        self::assertSame(
            [['vat_rate' => 12.0, 'amount' => 112.0], ['vat_rate' => 25.0, 'amount' => 25.0]],
            (new RefundVatSplit())->forCreditMemo($creditMemo)
        );
    }

    public function testRefundsTheInvoiceFeeAtTheRateQliroChargedIt(): void
    {
        $creditMemo = $this->creditMemo(
            141.0,
            [$this->line(12.0, 100.0, 12.0)],
            [],
            $this->order(0.0, 0.0, [['PricePerItemIncVat' => 29.0, 'PricePerItemExVat' => 23.2, 'VatRate' => 25.0]], 141.0, 141.0)
        );

        self::assertSame(
            [['vat_rate' => 12.0, 'amount' => 112.0], ['vat_rate' => 25.0, 'amount' => 29.0]],
            (new RefundVatSplit())->forCreditMemo($creditMemo)
        );
    }

    public function testLeavesTheFeeOutOfAMemoThatDoesNotCompleteTheRefund(): void
    {
        $creditMemo = $this->creditMemo(
            112.0,
            [$this->line(12.0, 100.0, 12.0)],
            [],
            $this->order(0.0, 0.0, [['PricePerItemIncVat' => 29.0, 'VatRate' => 25.0]], 253.0, 112.0)
        );

        self::assertSame([['vat_rate' => 12.0, 'amount' => 112.0]], (new RefundVatSplit())->forCreditMemo($creditMemo));
    }

    /**
     * The last memo's tax is Magento's remainder, so the fee is not told apart by its amount
     */
    public function testFindsTheFeeOnTheLastMemoWhateverTheRestHolds(): void
    {
        $creditMemo = $this->creditMemo(
            136.01,
            [$this->line(12.0, 100.0, 12.0)],
            ['adjustment_negative' => 5.0],
            $this->order(0.0, 0.0, [['PricePerItemIncVat' => 29.0, 'VatRate' => 25.0]], 253.0, 253.0)
        );

        $split = (new RefundVatSplit())->forCreditMemo($creditMemo);

        self::assertSame([12.0, 25.0], array_column($split, 'vat_rate'));
        self::assertSame(13601, (int)round(array_sum(array_column($split, 'amount')) * 100));
        self::assertEqualsWithDelta(29.0 * 136.01 / 141.0, $split[1]['amount'], 0.01);
    }

    public function testSpreadsAnAdjustmentOverTheRatesAndKeepsTheTotalToTheOre(): void
    {
        $creditMemo = $this->creditMemo(
            100.0,
            [$this->line(25.0, 100.0, 25.0), $this->line(12.0, 100.0, 12.0), $this->line(6.0, 100.0, 6.0)],
            ['adjustment_negative' => 243.0]
        );

        $split = (new RefundVatSplit())->forCreditMemo($creditMemo);

        self::assertSame([25.0, 12.0, 6.0], array_column($split, 'vat_rate'));
        self::assertSame([36.44, 32.65, 30.91], array_column($split, 'amount'));
        self::assertSame(10000, (int)round(array_sum(array_column($split, 'amount')) * 100));
    }

    public function testSkipsPlaceholderLinesAndLinesNotRefunded(): void
    {
        $creditMemo = $this->creditMemo(125.0, [
            $this->line(12.0, 100.0, 12.0, 0.0, 0.0),
            $this->line(6.0, 100.0, 6.0, 0.0, 1.0, true),
            $this->line(25.0, 100.0, 25.0),
        ]);

        self::assertSame([['vat_rate' => 25.0, 'amount' => 125.0]], (new RefundVatSplit())->forCreditMemo($creditMemo));
    }

    public function testAMemoWithNothingToWeighTakesTheRateItsLinesShare(): void
    {
        $creditMemo = $this->creditMemo(
            30.0,
            [],
            ['adjustment_positive' => 30.0],
            $this->order(0.0, 0.0, null, 1000.0, 0.0, [$this->orderLine(25.0), $this->orderLine(null), $this->orderLine(6.0, true)])
        );

        self::assertSame([['vat_rate' => 25.0, 'amount' => 30.0]], (new RefundVatSplit())->forCreditMemo($creditMemo));
    }

    public function testAMemoWithNothingToWeighAndMixedLinesStatesNoRate(): void
    {
        $creditMemo = $this->creditMemo(
            30.0,
            [],
            ['adjustment_positive' => 30.0],
            $this->order(0.0, 0.0, null, 1000.0, 0.0, [$this->orderLine(25.0), $this->orderLine(12.0)])
        );

        self::assertSame([['vat_rate' => 0.0, 'amount' => 30.0]], (new RefundVatSplit())->forCreditMemo($creditMemo));
    }

    public function testKeepsARateWithDecimals(): void
    {
        $creditMemo = $this->creditMemo(225.5, [$this->line(12.5, 100.0, 12.5), $this->line(13.0, 100.0, 0.0)]);

        self::assertSame([12.5, 13.0], array_column((new RefundVatSplit())->forCreditMemo($creditMemo), 'vat_rate'));
    }

    public function testReadsTheRateFromTheAmountsWhereTheLineHasNoTaxPercent(): void
    {
        $creditMemo = $this->creditMemo(
            212.0,
            [$this->line(null, 100.0, 20.0, 20.0), $this->line(12.0, 100.0, 12.0)],
            ['discount' => -20.0]
        );

        self::assertSame(
            [['vat_rate' => 12.0, 'amount' => 112.0], ['vat_rate' => 25.0, 'amount' => 100.0]],
            (new RefundVatSplit())->forCreditMemo($creditMemo)
        );
    }

    public function testSplitsSharesInTurnSoEachRateAddsUpOverAllOfThem(): void
    {
        $split = [
            ['vat_rate' => 25.0, 'amount' => 852.6],
            ['vat_rate' => 12.0, 'amount' => 852.6],
            ['vat_rate' => 6.0, 'amount' => 93.46],
        ];

        $splits = (new RefundVatSplit())->apportionEach($split, [1738.17, 60.49]);

        foreach ([0 => 1738.17, 1 => 60.49] as $index => $amount) {
            self::assertSame((int)round($amount * 100), (int)round(array_sum(array_column($splits[$index], 'amount')) * 100));
        }

        $byRate = [];
        foreach (array_merge(...$splits) as $entry) {
            $byRate[(string)$entry['vat_rate']] = ($byRate[(string)$entry['vat_rate']] ?? 0) + (int)round($entry['amount'] * 100);
        }

        self::assertSame(['25' => 85260, '12' => 85260, '6' => 9346], $byRate);
    }

    public function testApportionsAShareOfASplitToTheOre(): void
    {
        $split = [['vat_rate' => 25.0, 'amount' => 125.0], ['vat_rate' => 12.0, 'amount' => 112.0]];

        self::assertSame(
            [['vat_rate' => 25.0, 'amount' => 52.74], ['vat_rate' => 12.0, 'amount' => 47.26]],
            (new RefundVatSplit())->apportion($split, -100.0)
        );
    }

    private function creditMemo(float $grandTotal, array $items, array $totals = [], ?Order $order = null): Creditmemo&MockObject
    {
        $creditMemo = $this->createMock(Creditmemo::class);
        $creditMemo->method('getGrandTotal')->willReturn($grandTotal);
        $creditMemo->method('getAllItems')->willReturn($items);
        $creditMemo->method('getShippingAmount')->willReturn($totals['shipping'] ?? 0.0);
        $creditMemo->method('getShippingTaxAmount')->willReturn($totals['shipping_tax'] ?? 0.0);
        $creditMemo->method('getDiscountTaxCompensationAmount')->willReturn($totals['dtc'] ?? 0.0);
        $creditMemo->method('getDiscountAmount')->willReturn($totals['discount'] ?? 0.0);
        $creditMemo->method('getAdjustmentPositive')->willReturn($totals['adjustment_positive'] ?? 0.0);
        $creditMemo->method('getAdjustmentNegative')->willReturn($totals['adjustment_negative'] ?? 0.0);
        $creditMemo->method('getOrder')->willReturn($order ?? $this->order(0.0, 0.0));

        return $creditMemo;
    }

    private function order(
        float $shipping,
        float $shippingInclTax,
        ?array $fees = null,
        float $grandTotal = 1000.0,
        float $totalRefunded = 0.0,
        array $items = []
    ): Order&MockObject {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturn($fees);

        $order = $this->createMock(Order::class);
        $order->method('getShippingAmount')->willReturn($shipping);
        $order->method('getShippingInclTax')->willReturn($shippingInclTax);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('getTotalRefunded')->willReturn($totalRefunded);
        $order->method('getAllItems')->willReturn($items);

        return $order;
    }

    private function orderLine(?float $taxPercent, bool $isDummy = false): OrderItem&MockObject
    {
        $orderItem = $this->createMock(OrderItem::class);
        $orderItem->method('isDummy')->willReturn($isDummy);
        $orderItem->method('getTaxPercent')->willReturn($taxPercent);

        return $orderItem;
    }

    private function line(
        ?float $taxPercent,
        float $rowTotal,
        float $tax,
        float $discount = 0.0,
        float $qty = 1.0,
        bool $isDummy = false
    ): CreditmemoItem&MockObject {
        $orderItem = $this->createMock(OrderItem::class);
        $orderItem->method('isDummy')->willReturn($isDummy);
        $orderItem->method('getTaxPercent')->willReturn($taxPercent);

        $item = $this->createMock(CreditmemoItem::class);
        $item->method('getOrderItem')->willReturn($orderItem);
        $item->method('getQty')->willReturn($qty);
        $item->method('getRowTotal')->willReturn($rowTotal);
        $item->method('getTaxAmount')->willReturn($tax);
        $item->method('getDiscountAmount')->willReturn($discount);

        return $item;
    }
}
