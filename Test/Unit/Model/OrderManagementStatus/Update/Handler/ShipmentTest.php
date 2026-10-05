<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\OrderManagementStatus\Update\Handler;

use Magento\Directory\Model\Currency;
use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Shipment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\Notification\QliroOrderManagementStatus;
use Qliro\QliroOne\Model\OrderManagementStatus;
use Qliro\QliroOne\Model\OrderManagementStatus\Update\CaptureTransactionUpdater;
use Qliro\QliroOne\Model\OrderManagementStatus\Update\Handler\Shipment as ShipmentHandler;

/**
 * Qliro confirms a shipment capture after the store invoiced the order itself: the invoice already
 * took the capture, so the confirmation must not fail for want of something to invoice.
 *
 * @see \Qliro\QliroOne\Model\OrderManagementStatus\Update\Handler\Shipment::handleSuccess
 */
class ShipmentTest extends TestCase
{
    private InvoiceRepositoryInterface&MockObject $invoiceRepository;
    private CaptureTransactionUpdater&MockObject $transactionUpdater;

    public function testAnOrderTheStoreAlreadyInvoicedIsConfirmedWithoutAnotherInvoice(): void
    {
        $order = $this->order(canInvoice: false, hasInvoices: true, qtyInvoiced: 1);

        $this->invoiceRepository->expects(self::never())->method('save');
        $this->transactionUpdater->expects(self::once())->method('update');

        $this->handler($order)->handleSuccess($this->qliroStatus(), $this->omStatus());
    }

    /**
     * Still open for other lines, but nothing of this shipment is left to invoice
     */
    public function testAShipmentWhoseLinesAreAllInvoicedCreatesNoEmptyInvoice(): void
    {
        $order = $this->order(canInvoice: true, hasInvoices: true, qtyInvoiced: 1);
        $order->expects(self::never())->method('prepareInvoice');

        $this->transactionUpdater->expects(self::once())->method('update');

        $this->handler($order)->handleSuccess($this->qliroStatus(), $this->omStatus());
    }

    /**
     * Partly invoiced by the store, the rest of this shipment still to invoice, and Magento refusing:
     * that is a real mismatch, not a confirmation to swallow
     */
    public function testAShipmentLeftToInvoiceThatMagentoRefusesStillFails(): void
    {
        $order = $this->order(canInvoice: false, hasInvoices: true, qtyInvoiced: 0);

        $this->expectException(TerminalException::class);

        $this->handler($order)->handleSuccess($this->qliroStatus(), $this->omStatus());
    }

    public function testAnOrderThatCannotBeInvoicedForAnotherReasonStillFails(): void
    {
        $order = $this->order(canInvoice: false, hasInvoices: false, qtyInvoiced: 0);

        $this->expectException(TerminalException::class);

        $this->handler($order)->handleSuccess($this->qliroStatus(), $this->omStatus());
    }

    private function handler(Order $order): ShipmentHandler
    {
        $shipmentItem = $this->createMock(Shipment\Item::class);
        $shipmentItem->method('getQty')->willReturn(1.0);
        $shipmentItem->method('getOrderItemId')->willReturn(3);

        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getOrder')->willReturn($order);
        $shipment->method('getAllItems')->willReturn([$shipmentItem]);
        $shipment->method('getId')->willReturn(5);

        $shipmentRepository = $this->createMock(ShipmentRepositoryInterface::class);
        $shipmentRepository->method('get')->willReturn($shipment);

        return new ShipmentHandler(
            $shipmentRepository,
            $this->createMock(OrderRepositoryInterface::class),
            $this->invoiceRepository,
            $this->createMock(Manager::class),
            $this->transactionUpdater
        );
    }

    protected function setUp(): void
    {
        $this->invoiceRepository = $this->createMock(InvoiceRepositoryInterface::class);
        $this->transactionUpdater = $this->createMock(CaptureTransactionUpdater::class);
    }

    private function order(bool $canInvoice, bool $hasInvoices, float $qtyInvoiced): Order&MockObject
    {
        $item = $this->createMock(OrderItem::class);
        $item->method('getQtyOrdered')->willReturn(1.0);
        $item->method('getQtyInvoiced')->willReturn($qtyInvoiced);

        $currency = $this->createMock(Currency::class);
        $currency->method('formatTxt')->willReturn('527.00 NOK');

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($this->createMock(Payment::class));
        $order->method('getItemById')->willReturn($item);
        $order->method('canInvoice')->willReturn($canInvoice);
        $order->method('hasInvoices')->willReturn($hasInvoices);
        $order->method('isCanceled')->willReturn(false);
        $order->method('getBaseCurrency')->willReturn($currency);

        return $order;
    }

    private function qliroStatus(): QliroOrderManagementStatus&MockObject
    {
        $status = $this->createMock(QliroOrderManagementStatus::class);
        $status->method('getPaymentTransactionId')->willReturn(331743203);
        $status->method('getAmount')->willReturn(527.0);
        $status->method('getOrderId')->willReturn(283369012);

        return $status;
    }

    private function omStatus(): OrderManagementStatus&MockObject
    {
        $status = $this->createMock(OrderManagementStatus::class);
        $status->method('getRecordId')->willReturn(5);

        return $status;
    }
}
