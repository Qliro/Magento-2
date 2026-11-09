<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Admin\CreditMemo;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\QliroOrder\Admin\CreditMemo\InvoiceFeeTotalValidator;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Admin\CreditMemo\InvoiceFeeTotalValidator
 */
class InvoiceFeeTotalValidatorTest extends TestCase
{
    private const FEE = 29.0;

    public function testRefundShortOfTheOrderByLessThanAUnitKeepsTheFee(): void
    {
        // 99.60 of goods, 99.10 refunded with this memo
        $memo = $this->creditMemo(grandTotal: 128.60, totalRefunded: 0.0, memoTotal: 99.10);

        $this->assertFalse($this->validator($memo)->validate(false));
    }

    public function testRefundOfTheWholeOrderReturnsTheFee(): void
    {
        $memo = $this->creditMemo(grandTotal: 128.60, totalRefunded: 50.0, memoTotal: 49.60);

        $this->assertTrue($this->validator($memo)->validate(false));
    }

    public function testMemoTotalWithTheFeeAddedIsComparedWithoutIt(): void
    {
        $full = $this->creditMemo(grandTotal: 128.60, totalRefunded: 50.0, memoTotal: 49.60 + self::FEE);
        $short = $this->creditMemo(grandTotal: 128.60, totalRefunded: 50.0, memoTotal: 49.59 + self::FEE);

        $this->assertTrue($this->validator($full)->validate());
        $this->assertFalse($this->validator($short)->validate());
    }

    public function testBaseAmountsOfAnOrderInAnotherCurrencyAreNotUsed(): void
    {
        // order in EUR on a SEK base store: base amounts are about eleven times the order ones
        $memo = $this->creditMemo(
            grandTotal: 128.60,
            totalRefunded: 10.0,
            memoTotal: 50.0,
            baseGrandTotal: 1414.60,
            baseTotalRefunded: 110.0
        );
        $this->assertFalse($this->validator($memo)->validate(false));

        $memo = $this->creditMemo(
            grandTotal: 128.60,
            totalRefunded: 50.0,
            memoTotal: 49.60,
            baseGrandTotal: 1414.60,
            baseTotalRefunded: 550.0
        );
        $this->assertTrue($this->validator($memo)->validate(false));
    }

    public function testFullyRefundedOrderByRefundedTotalOnly(): void
    {
        $short = $this->creditMemo(grandTotal: 128.60, totalRefunded: 128.10, memoTotal: 0.0, baseTotalRefunded: 1500.0);
        $full = $this->creditMemo(grandTotal: 128.60, totalRefunded: 128.60, memoTotal: 0.0);

        $this->assertFalse($this->validator($short)->validate(true, true));
        $this->assertTrue($this->validator($full)->validate(true, true));
    }

    public function testNoFeeNothingToRefund(): void
    {
        $memo = $this->creditMemo(grandTotal: 99.60, totalRefunded: 0.0, memoTotal: 99.60, fees: []);

        $this->assertFalse($this->validator($memo)->validate(false));
    }

    public function testFeeStoredAsTextIsRead(): void
    {
        $memo = $this->creditMemo(grandTotal: 128.60, totalRefunded: 0.0, memoTotal: 99.60, fees: [['PricePerItemIncVat' => '29.00']]);

        $this->assertTrue($this->validator($memo)->validate(false));
    }

    public function testMemoWithoutAnInvoiceKeepsTheFee(): void
    {
        $memo = $this->createMock(Creditmemo::class);
        $memo->method('getInvoice')->willReturn(null);

        $this->assertFalse($this->validator($memo)->validate(false));
    }

    public function testFeeIsReadAgainForAnotherCreditMemo(): void
    {
        $validator = new InvoiceFeeTotalValidator();
        $withFee = $this->creditMemo(grandTotal: 128.60, totalRefunded: 0.0, memoTotal: 99.60);
        $withoutFee = $this->creditMemo(grandTotal: 99.60, totalRefunded: 0.0, memoTotal: 99.60, fees: []);

        $this->assertTrue($validator->setCreditMemo($withFee)->validate(false));
        $this->assertFalse($validator->setCreditMemo($withoutFee)->validate(false));
    }

    public function testSavedMemoCountsOnlyTheMemosBeforeIt(): void
    {
        $first = $this->creditMemo(grandTotal: 128.60, totalRefunded: 128.60, memoTotal: 50.0, id: 1);
        $last = $this->creditMemo(grandTotal: 128.60, totalRefunded: 128.60, memoTotal: 49.60 + self::FEE, id: 2);
        $earlier = [$first, $last];
        foreach ($earlier as $memo) {
            $memo->getOrder()->method('getCreditmemosCollection')->willReturn($earlier);
        }

        $this->assertFalse($this->validator($first)->validate());
        $this->assertTrue($this->validator($last)->validate());
    }

    private function validator(Creditmemo $memo): InvoiceFeeTotalValidator
    {
        return (new InvoiceFeeTotalValidator())->setCreditMemo($memo);
    }

    private function creditMemo(
        float $grandTotal,
        float $totalRefunded,
        float $memoTotal,
        ?float $baseGrandTotal = null,
        ?float $baseTotalRefunded = null,
        ?array $fees = null,
        ?int $id = null
    ): Creditmemo {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            fn ($key) => $key === 'qliroone_fees' ? ($fees ?? [['PricePerItemIncVat' => self::FEE]]) : null
        );

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getGrandTotal')->willReturn($grandTotal);
        $order->method('getTotalRefunded')->willReturn($totalRefunded);
        $order->method('getBaseGrandTotal')->willReturn($baseGrandTotal ?? $grandTotal);
        $order->method('getBaseTotalRefunded')->willReturn($baseTotalRefunded ?? $totalRefunded);

        $memo = $this->createMock(Creditmemo::class);
        $memo->method('getOrder')->willReturn($order);
        $memo->method('getEntityId')->willReturn($id);
        $memo->method('getInvoice')->willReturn($this->createMock(Invoice::class));
        $memo->method('getGrandTotal')->willReturn($memoTotal);
        $memo->method('getBaseGrandTotal')->willReturn($memoTotal * 11);

        return $memo;
    }
}
