<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Admin\Builder;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Invoice\Item as InvoiceItem;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Item as ShipmentItem;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroShipmentInterfaceFactory;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Product\Type\OrderSourceProvider;
use Qliro\QliroOne\Model\Product\Type\TypePoolHandler;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\InvoiceShipmentsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\ShipmentShipmentsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\Shipment as QliroShipment;

/**
 * Qliro matches a capture against the reservation by the merchant reference of each line, so the
 * capture of an order placed before the reference carried the cart item id has to keep the shape
 * that order was reserved with (PLIN-408).
 *
 * @see \Qliro\QliroOne\Model\QliroOrder\Admin\Builder\InvoiceShipmentsBuilder
 * @see \Qliro\QliroOne\Model\QliroOrder\Admin\Builder\ShipmentShipmentsBuilder
 */
class CaptureLineReferenceTest extends TestCase
{
    public function testTheInvoiceCaptureOfAStampedOrderNamesTheCartItem(): void
    {
        self::assertSame(['518:Kanalplast'], $this->captureFromInvoice(true));
    }

    public function testTheInvoiceCaptureOfAnOlderOrderKeepsTheBareSku(): void
    {
        self::assertSame(['Kanalplast'], $this->captureFromInvoice(false));
    }

    public function testTheShipmentCaptureOfAStampedOrderNamesTheCartItem(): void
    {
        self::assertSame(['518:Kanalplast'], $this->captureFromShipment(true));
    }

    public function testTheShipmentCaptureOfAnOlderOrderKeepsTheBareSku(): void
    {
        self::assertSame(['Kanalplast'], $this->captureFromShipment(false));
    }

    /**
     * @param bool $stamped
     * @return string[]
     */
    private function captureFromInvoice(bool $stamped): array
    {
        $order = $this->buildOrder($stamped);

        $invoiceItem = $this->createMock(InvoiceItem::class);
        $invoiceItem->method('getOrderItemId')->willReturn(9);
        $invoiceItem->method('getQty')->willReturn(25.0);

        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getAllItems')->willReturn([$invoiceItem]);

        // getInvoice() is a magic getter on the payment, so the mock has to be told it exists
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->addMethods(['getInvoice'])
            ->onlyMethods(['getOrder'])
            ->getMock();
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getInvoice')->willReturn($invoice);

        $builder = new InvoiceShipmentsBuilder(
            $this->buildTypeResolver(),
            $this->buildShipmentFactory(),
            $this->buildSourceProvider()
        );
        $builder->setPayment($payment);

        return $this->referencesOf($builder->create());
    }

    /**
     * @param bool $stamped
     * @return string[]
     */
    private function captureFromShipment(bool $stamped): array
    {
        $order = $this->buildOrder($stamped);

        $shipmentItem = $this->createMock(ShipmentItem::class);
        $shipmentItem->method('getOrderItemId')->willReturn(9);
        $shipmentItem->method('getQty')->willReturn(25.0);

        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getItemsCollection')->willReturn([$shipmentItem]);
        $shipment->method('getOrder')->willReturn($order);

        $builder = new ShipmentShipmentsBuilder(
            $this->buildTypeResolver(),
            $this->buildShipmentFactory(),
            $this->buildSourceProvider()
        );
        $builder->setShipment($shipment);

        return $this->referencesOf($builder->create());
    }

    private function buildOrder(bool $stamped): Order
    {
        $orderItem = $this->createMock(OrderItem::class);
        $orderItem->method('getId')->willReturn(9);
        $orderItem->method('getProductType')->willReturn('simple');
        $orderItem->method('getParentItemId')->willReturn(null);

        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')
            ->willReturnCallback(
                static fn($key = null) => $key === Config::QLIROONE_ADDITIONAL_INFO_LINE_REFERENCE_CARRIES_ITEM_ID
                    ? $stamped
                    : null
            );

        $order = $this->createMock(Order::class);
        $order->method('getItemById')->willReturn($orderItem);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getInvoiceCollection')->willReturn([]);
        $order->method('getShipmentsCollection')->willReturn([]);

        return $order;
    }

    private function buildSourceProvider(): OrderSourceProvider
    {
        $sourceProvider = $this->createMock(OrderSourceProvider::class);
        $sourceProvider->method('generateSourceItem')
            ->willReturn($this->createMock(TypeSourceItemInterface::class));

        return $sourceProvider;
    }

    private function buildTypeResolver(): TypePoolHandler
    {
        $line = new Item();
        $line->setMerchantReference('518:Kanalplast');
        $line->setType(QliroOrderItemInterface::TYPE_PRODUCT);

        $typeResolver = $this->createMock(TypePoolHandler::class);
        $typeResolver->method('resolveQliroOrderItem')->willReturn($line);

        return $typeResolver;
    }

    private function buildShipmentFactory(): QliroShipmentInterfaceFactory
    {
        $factory = $this->createMock(QliroShipmentInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(static fn(): QliroShipment => new QliroShipment());

        return $factory;
    }

    /**
     * @param \Qliro\QliroOne\Api\Data\QliroShipmentInterface[] $shipments
     * @return string[]
     */
    private function referencesOf(array $shipments): array
    {
        self::assertCount(1, $shipments);

        return array_map(
            static fn(QliroOrderItemInterface $item): string => $item->getMerchantReference(),
            $shipments[0]->getOrderItems()
        );
    }
}
