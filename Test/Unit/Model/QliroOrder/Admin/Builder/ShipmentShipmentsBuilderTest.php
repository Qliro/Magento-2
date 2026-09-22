<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Admin\Builder;

use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Item as ShipmentItem;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Api\Data\QliroShipmentInterfaceFactory;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterfaceFactory;
use Qliro\QliroOne\Helper\Data as QliroHelper;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Product\ProductPool;
use Qliro\QliroOne\Model\Product\Type\Handler\BundleHandler;
use Qliro\QliroOne\Model\Product\Type\Handler\DefaultHandler;
use Qliro\QliroOne\Model\Product\Type\OrderSourceProvider;
use Qliro\QliroOne\Model\Product\Type\TypePoolHandler;
use Qliro\QliroOne\Model\Product\Type\TypeResolver;
use Qliro\QliroOne\Model\Product\Type\TypeSourceItem;
use Qliro\QliroOne\Model\Product\VatRate;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\ShipmentShipmentsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\LineVatRate;
use Qliro\QliroOne\Model\QliroOrder\Shipment as QliroShipment;

/**
 * The lines a capture on shipment is sent to Qliro as. The quantity decides what the buyer is
 * charged, so a quantity Qliro cannot carry is refused rather than settled at another number.
 *
 * @see \Qliro\QliroOne\Model\QliroOrder\Admin\Builder\ShipmentShipmentsBuilder
 */
class ShipmentShipmentsBuilderTest extends TestCase
{
    /**
     * A shipment carries the quantity being shipped now, at the amounts of the order line.
     */
    public function testCapturesTheQuantityBeingShipped(): void
    {
        $item = $this->orderItem('SKU-1', 5.0);

        $lines = $this->build([$item], [1 => 2.0])[0]->getOrderItems();

        self::assertCount(1, $lines);
        self::assertSame(2.0, $lines[0]->getQuantity());
        self::assertSame(125.0, $lines[0]->getPricePerItemIncVat());
    }

    /**
     * Qliro carries a whole quantity only, so half a metre is refused rather than truncated: the
     * `(int)` cast this replaced shipped 0.5 as 0, which dropped the line out of the capture
     * while Magento recorded the whole shipment.
     */
    public function testRefusesToCaptureAPartOfAnItem(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/CABLE-5MM/');

        $this->build([$this->orderItem('CABLE-5MM', 5.0)], [1 => 0.5]);
    }

    /**
     * A shipment of nothing sends no capture rather than an empty one.
     */
    public function testSendsNoShipmentWhenNothingIsBeingShipped(): void
    {
        self::assertSame([], $this->build([$this->orderItem('SKU-1', 5.0)], [1 => 0.0]));
    }

    /**
     * A line the loop skips is never sent, so its quantity has nothing to refuse. Refusing here
     * would leave a store capturing on shipment unable to ship the order at all, the capture
     * runs inside the shipment save.
     */
    public function testAPartOfAnItemOnALineThatIsNeverSentDoesNotStopTheShipment(): void
    {
        $bundle = $this->orderItem('KIT', 1.0, ['id' => 1, 'type' => 'bundle']);
        $child = $this->orderItem('COFFEE-KG', 0.5, ['id' => 2, 'parentId' => 1]);

        $lines = $this->build([$bundle, $child], [1 => 1.0, 2 => 0.5])[0]->getOrderItems();

        self::assertCount(1, $lines);
        self::assertSame(1.0, $lines[0]->getQuantity());
    }

    /**
     * What is left to invoice replaces the shipped quantity on a configurable, and a legacy
     * partial invoice can leave a fraction of one. That quantity is the one that reaches Qliro,
     * so it is the one that has to be whole.
     */
    public function testRefusesAFractionLeftToInvoiceOnAConfigurable(): void
    {
        $parent = $this->orderItem('SHIRT', 3.0, ['id' => 1, 'type' => 'configurable', 'qtyInvoiced' => 1.5]);
        $child = $this->orderItem('SHIRT-BLUE', 3.0, ['id' => 2, 'parentId' => 1]);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/SHIRT/');

        $this->build([$parent, $child], [1 => 2.0, 2 => 2.0]);
    }

    public function testRefusesToBuildWithoutAnOrder(): void
    {
        $this->expectException(\LogicException::class);

        $this->builder()->create();
    }

    /**
     * @param OrderItem[] $orderItems
     * @param array<int, float> $shippedQuantities Shipped quantity per order item id
     * @return QliroShipment[]
     */
    private function build(array $orderItems, array $shippedQuantities): array
    {
        $builder = $this->builder();
        $builder->setShipment($this->shipment($this->order($orderItems), $shippedQuantities));

        return $builder->create();
    }

    private function builder(): ShipmentShipmentsBuilder
    {
        $shipmentFactory = $this->createMock(QliroShipmentInterfaceFactory::class);
        $shipmentFactory->method('create')->willReturnCallback(static fn(): QliroShipment => new QliroShipment());

        $sourceItemFactory = $this->createMock(TypeSourceItemInterfaceFactory::class);
        $sourceItemFactory->method('create')->willReturnCallback(static fn(): TypeSourceItem => new TypeSourceItem());

        return new ShipmentShipmentsBuilder(
            $this->typePool(),
            $shipmentFactory,
            new OrderSourceProvider($this->createMock(ProductPool::class), $sourceItemFactory)
        );
    }

    private function typePool(): TypePoolHandler
    {
        $itemFactory = $this->createMock(QliroOrderItemInterfaceFactory::class);
        $itemFactory->method('create')->willReturnCallback(static fn(): Item => new Item());

        $qliroHelper = $this->createMock(QliroHelper::class);
        $qliroHelper->method('formatPrice')
            ->willReturnCallback(static fn($value): string => number_format((float)$value, 2, '.', ''));

        $config = $this->createMock(Config::class);
        $config->method('isIngridEnabled')->willReturn(false);

        $vatRate = $this->createMock(VatRate::class);
        $vatRate->method('getVatRateForProduct')->willReturn(0.0);

        $handler = new DefaultHandler($itemFactory, $qliroHelper, $config, $vatRate, new LineVatRate());
        $bundle = new BundleHandler($itemFactory, $qliroHelper, $config, $vatRate, new LineVatRate());

        // As `etc/di.xml` wires it, see OrderItemsBuilderTest
        return new TypePoolHandler($this->createMock(TypeResolver::class), [
            'simple' => $handler,
            'configurable' => null,
            'simple:configurable' => $handler,
            'bundle' => $bundle,
            'simple:bundle' => $bundle,
        ]);
    }

    /**
     * @param array<int, float> $shippedQuantities
     */
    private function shipment(Order $order, array $shippedQuantities): Shipment&MockObject
    {
        $items = [];

        foreach ($shippedQuantities as $orderItemId => $qty) {
            $item = $this->createMock(ShipmentItem::class);
            $item->method('getOrderItemId')->willReturn($orderItemId);
            $item->method('getQty')->willReturn($qty);
            $items[] = $item;
        }

        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getId')->willReturn(1);
        $shipment->method('getOrder')->willReturn($order);
        $shipment->method('getItemsCollection')->willReturn($items);

        return $shipment;
    }

    /**
     * @param OrderItem[] $items
     */
    private function order(array $items): Order&MockObject
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getItemById',
                'getInvoiceCollection',
                'getShipmentsCollection',
                'getStoreId',
                'getPayment',
            ])
            ->addMethods(['setFirstCaptureFlag'])
            ->getMock();

        // The lines go out as this version builds them, see LineReference::alignWithReservation
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturn(true);

        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getInvoiceCollection')->willReturn([]);
        $order->method('getShipmentsCollection')->willReturn([]);
        $order->method('getItemById')->willReturnCallback(
            static function ($id) use ($items): ?OrderItem {
                foreach ($items as $item) {
                    if ((int)$item->getId() === (int)$id) {
                        return $item;
                    }
                }

                return null;
            }
        );

        return $order;
    }

    private function orderItem(string $sku, float $qtyOrdered, array $data = []): OrderItem&MockObject
    {
        $type = $data['type'] ?? 'simple';

        $product = $this->createMock(Product::class);
        $product->method('getTypeId')->willReturn($type);
        $product->method('getStoreId')->willReturn(1);

        $item = $this->createMock(OrderItem::class);
        $item->method('getId')->willReturn($data['id'] ?? 1);
        $item->method('getQuoteItemId')->willReturn($data['id'] ?? 1);
        $item->method('getSku')->willReturn($sku);
        $item->method('getName')->willReturn('Line');
        $item->method('getProductType')->willReturn($type);
        $item->method('getQtyOrdered')->willReturn($qtyOrdered);
        $item->method('getQtyInvoiced')->willReturn($data['qtyInvoiced'] ?? 0.0);
        $item->method('getPriceInclTax')->willReturn(125.0);
        $item->method('getPrice')->willReturn(100.0);
        $item->method('getTaxPercent')->willReturn(25.0);
        $item->method('getParentItemId')->willReturn($data['parentId'] ?? null);
        $item->method('getParentItem')->willReturn(null);
        $item->method('getProduct')->willReturn($product);

        return $item;
    }
}
