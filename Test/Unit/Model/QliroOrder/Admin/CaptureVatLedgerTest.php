<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Admin;

use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\QliroOrder\Admin\CaptureVatLedger;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\Shipment;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Admin\CaptureVatLedger
 */
class CaptureVatLedgerTest extends TestCase
{
    private array $info = [];

    public function testRecordsWhatACaptureHoldsUnderEachRateNetOfItsDiscounts(): void
    {
        $payment = $this->payment();

        (new CaptureVatLedger())->recordCapture($payment, '3001', [$this->shipment([
            $this->line('SKU-1', 'Product', 2, 100.0, 80.0, 25.0),
            $this->line('DSC_SKU-1', 'Discount', 2, -10.0, -8.0, 25.0),
            $this->line('flatrate_flatrate', 'Shipping', 1, 49.0, 39.2, 25.0),
            $this->line('EBOOK-1', 'Product', 1, 50.0, 47.17, 6.0),
        ])]);

        self::assertSame(['25' => 229.0, '6' => 50.0], (new CaptureVatLedger())->available($payment, '3001'));
    }

    public function testKeepsWhatACaptureFirstHeldWhenItIsRecordedAgain(): void
    {
        $payment = $this->payment();
        $ledger = new CaptureVatLedger();

        $ledger->recordCapture($payment, '3001', [$this->shipment([$this->line('A', 'Product', 1, 100.0, 80.0, 25.0)])]);
        $ledger->recordCapture($payment, '3001', [$this->shipment([$this->line('B', 'Product', 1, 50.0, 44.64, 12.0)])]);

        self::assertSame(['25' => 100.0], $ledger->available($payment, '3001'));
    }

    public function testReadsTheRateFromThePricesOfALineThatStatesNone(): void
    {
        $payment = $this->payment();

        (new CaptureVatLedger())->recordCapture($payment, '3001', [$this->shipment([
            $this->line('A', 'Product', 1, 112.0, 100.0, 0.0),
            $this->line('GIFT', 'Product', 1, 30.0, 30.0, 0.0),
        ])]);

        self::assertSame(['12' => 112.0, '0' => 30.0], (new CaptureVatLedger())->available($payment, '3001'));
    }

    public function testKnowsNothingOfACaptureItNeverRecorded(): void
    {
        self::assertNull((new CaptureVatLedger())->available($this->payment(), '3001'));
    }

    public function testCountsAConfirmedRefundUnderTheRatesItWasSentWith(): void
    {
        $payment = $this->payment();
        $ledger = $this->recorded($payment);

        $ledger->recordSent($payment, '4001', '3001', [['vat_rate' => 6.0, 'amount' => 20.0]]);
        $ledger->confirm($payment, '4001', '3001', -20.0);

        self::assertSame(['25' => 200.0, '6' => 30.0], $ledger->available($payment, '3001'));
        self::assertSame([], $this->info[CaptureVatLedger::KEY]['sent']);
    }

    public function testSpreadsARefundItHasNoSplitForOverWhatTheCaptureHasLeft(): void
    {
        $payment = $this->payment();
        $ledger = $this->recorded($payment);

        $ledger->confirm($payment, '4001', '3001', 25.0);

        self::assertSame(['25' => 180.0, '6' => 45.0], $ledger->available($payment, '3001'));
    }

    public function testForgetsARefundQliroRefused(): void
    {
        $payment = $this->payment();
        $ledger = $this->recorded($payment);

        $ledger->recordSent($payment, '4001', '3001', [['vat_rate' => 6.0, 'amount' => 20.0]]);
        $ledger->drop($payment, '4001');

        self::assertSame([], $this->info[CaptureVatLedger::KEY]['sent']);
        self::assertSame(['25' => 200.0, '6' => 50.0], $ledger->available($payment, '3001'));
    }

    public function testLeavesACaptureItNeverRecordedAlone(): void
    {
        $payment = $this->payment();
        $ledger = new CaptureVatLedger();

        $ledger->recordSent($payment, '4001', '3001', [['vat_rate' => 25.0, 'amount' => 20.0]]);
        $ledger->confirm($payment, '4001', '3001', 20.0);

        self::assertNull($ledger->available($payment, '3001'));
        self::assertSame([], $this->info[CaptureVatLedger::KEY]['sent']);
    }

    private function recorded(Payment $payment): CaptureVatLedger
    {
        $ledger = new CaptureVatLedger();
        $ledger->recordCapture($payment, '3001', [$this->shipment([
            $this->line('BOOK', 'Product', 1, 200.0, 160.0, 25.0),
            $this->line('EBOOK', 'Product', 1, 50.0, 47.17, 6.0),
        ])]);

        return $ledger;
    }

    private function payment(): Payment&MockObject
    {
        $this->info = [];
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            fn($key = null) => $key === null ? $this->info : ($this->info[$key] ?? null)
        );
        $payment->method('setAdditionalInformation')->willReturnCallback(
            function ($key, $value = null) use (&$payment) {
                $this->info[$key] = $value;

                return $payment;
            }
        );

        return $payment;
    }

    private function shipment(array $lines): Shipment
    {
        $shipment = new Shipment();
        $shipment->setOrderItems($lines);

        return $shipment;
    }

    private function line(string $reference, string $type, float $quantity, float $incVat, float $exVat, float $rate): Item
    {
        return (new Item())
            ->setMerchantReference($reference)
            ->setType($type)
            ->setQuantity($quantity)
            ->setPricePerItemIncVat($incVat)
            ->setPricePerItemExVat($exVat)
            ->setVatRate($rate);
    }
}
