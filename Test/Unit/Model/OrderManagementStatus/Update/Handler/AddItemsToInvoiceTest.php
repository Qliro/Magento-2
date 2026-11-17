<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\OrderManagementStatus\Update\Handler;

use Magento\Directory\Model\Currency;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\Notification\QliroOrderManagementStatus;
use Qliro\QliroOne\Model\OrderManagementStatus;
use Qliro\QliroOne\Model\OrderManagementStatus\Update\Handler\AddItemsToInvoice;
use Qliro\QliroOne\Model\QliroOrder\Admin\CaptureRefundAllocator;
use Qliro\QliroOne\Model\QliroOrder\Admin\CaptureVatLedger;
use Qliro\QliroOne\Model\QliroOrder\Admin\SequentialRefundProcessor;

/**
 * @see \Qliro\QliroOne\Model\OrderManagementStatus\Update\Handler\AddItemsToInvoice
 */
class AddItemsToInvoiceTest extends TestCase
{
    private array $calls = [];

    /**
     * Saving the order writes the payment it loaded on its own, so the refund is counted on the
     * payment after that and saved again, where the order save cannot undo it
     */
    public function testCountsAConfirmedRefundOnItsCaptureAfterTheOrderIsSaved(): void
    {
        $payment = $this->payment();

        $ledger = $this->createMock(CaptureVatLedger::class);
        $ledger->expects(self::once())->method('confirm')
            ->with($payment, '4001', '3001', 50.0)
            ->willReturnCallback(function () {
                $this->calls[] = 'confirm';
            });

        $this->handler($payment, $ledger)->handleSuccess($this->notification(), $this->omStatus());

        self::assertSame(['save order', 'confirm', 'save payment', 'continue'], $this->calls);
    }

    public function testAVatLedgerThatCannotBeSavedDoesNotFailTheCallback(): void
    {
        $payment = $this->payment();

        $ledger = $this->createMock(CaptureVatLedger::class);
        $ledger->method('confirm')->willThrowException(new \RuntimeException('Deadlock found'));

        $this->handler($payment, $ledger)->handleSuccess($this->notification(), $this->omStatus());

        self::assertContains('continue', $this->calls);
    }

    public function testForgetsTheSplitOfARefundQliroRefused(): void
    {
        $payment = $this->payment();

        $ledger = $this->createMock(CaptureVatLedger::class);
        $ledger->expects(self::once())->method('drop')->with($payment, '4001');

        $this->handler($payment, $ledger)->handleError($this->notification(), $this->omStatus());
    }

    private function handler(Payment $payment, CaptureVatLedger $ledger): AddItemsToInvoice
    {
        $payments = $this->createMock(OrderPaymentRepositoryInterface::class);
        $payments->method('get')->willReturn($payment);
        $payments->method('save')->willReturnCallback(function () use ($payment) {
            $this->calls[] = 'save payment';

            return $payment;
        });

        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('save')->willReturnCallback(function ($order) {
            $this->calls[] = 'save order';

            return $order;
        });

        $processor = $this->createMock(SequentialRefundProcessor::class);
        $processor->method('continueQueue')->willReturnCallback(function () {
            $this->calls[] = 'continue';

            return false;
        });
        $processor->method('abort')->willReturn([]);

        return new AddItemsToInvoice(
            $payments,
            $orders,
            $this->createMock(Manager::class),
            $this->createMock(CaptureRefundAllocator::class),
            $processor,
            $ledger
        );
    }

    private function payment(): Payment
    {
        $currency = $this->createMock(Currency::class);
        $currency->method('formatTxt')->willReturn('50,00 kr');

        $order = $this->createMock(Order::class);
        $order->method('getBaseCurrency')->willReturn($currency);

        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrder', 'getId'])
            ->getMock();
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getId')->willReturn(17);

        return $payment;
    }

    private function notification(): QliroOrderManagementStatus
    {
        $notification = $this->createMock(QliroOrderManagementStatus::class);
        $notification->method('getPaymentTransactionId')->willReturn(4001);
        $notification->method('getOriginalPaymentTransactionId')->willReturn(3001);
        $notification->method('getAmount')->willReturn(-50.0);

        return $notification;
    }

    private function omStatus(): OrderManagementStatus
    {
        $omStatus = $this->createMock(OrderManagementStatus::class);
        $omStatus->method('getRecordId')->willReturn(17);

        return $omStatus;
    }
}
