<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Admin\Builder;

use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\AppliedRulesHandler;
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
     * A shipment that leaves nothing to ship is the last capture, a virtual item that is never
     * shipped does not keep it from being one.
     */
    public function testFlagsTheLastShipmentPastAVirtualItem(): void
    {
        $shipped = $this->orderItem('SKU-1', 2.0);
        $shipped->method('getQtyToShip')->willReturn(0.0);
        $virtual = $this->orderItem('SKU-V', 1.0, ['id' => 2]);
        $virtual->method('getIsVirtual')->willReturn(true);
        $virtual->method('getQtyToShip')->willReturn(1.0);

        $order = $this->order([$shipped, $virtual]);
        $builder = $this->builder();
        $builder->setShipment($this->shipment($order, [1 => 2.0]));
        $builder->create();

        self::assertTrue($order->getData(AppliedRulesHandler::FINAL_CAPTURE_FLAG));
    }

    /**
     * Units left to ship on a physical item mean another capture follows.
     */
    public function testDoesNotFlagAShipmentThatLeavesUnitsToShip(): void
    {
        $item = $this->orderItem('SKU-1', 3.0);
        $item->method('getQtyToShip')->willReturn(1.0);

        $order = $this->order([$item]);
        $builder = $this->builder();
        $builder->setShipment($this->shipment($order, [1 => 2.0]));
        $builder->create();

        self::assertFalse($order->getData(AppliedRulesHandler::FINAL_CAPTURE_FLAG));
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

    /**
     * A bundle priced by its children and shipped together leaves only the bundle row in the
     * shipment. Its children are the lines the checkout charged, each ships its share.
     */
    public function testCapturesTheChildrenOfABundleShippedTogether(): void
    {
        [$bundle, $first, $second] = $this->bundlePricedByChildren(false);

        // Magento keeps the children in the shipment it builds, marked deleted, and saves the bundle row only
        $lines = $this->build([$bundle, $first, $second], [1 => 1.0, 2 => 1.0, 3 => 3.0])[0]->getOrderItems();

        self::assertSame(
            [['1:KIT', 1.0], ['2:KIT-A', 1.0], ['3:KIT-B', 3.0]],
            array_map(static fn($line): array => [$line->getMerchantReference(), $line->getQuantity()], $lines)
        );
    }

    /**
     * What an earlier capture took of the children is left out, and a child Qliro holds none of
     * any more is not sent at all. Magento's invoiced quantity cannot tell, an invoice captures
     * only with capture on invoice on.
     */
    public function testLeavesOutWhatQliroAlreadyCapturedOfTheChildren(): void
    {
        [$bundle, $first, $second] = $this->bundlePricedByChildren(false);
        $info = [
            Config::QLIROONE_ADDITIONAL_INFO_RESERVED_DISCOUNT => [
                'products' => ['1:KIT' => 2, '2:KIT-A' => 4, '3:KIT-B' => 6],
                'discounts' => [],
            ],
            Config::QLIROONE_ADDITIONAL_INFO_CAPTURED_UNITS => ['1:KIT' => 2, '2:KIT-A' => 4, '3:KIT-B' => 3],
        ];

        $lines = $this->build([$bundle, $first, $second], [1 => 2.0], $info)[0]->getOrderItems();

        self::assertSame(
            [['3:KIT-B', 3.0]],
            array_map(static fn($line): array => [$line->getMerchantReference(), $line->getQuantity()], $lines)
        );
    }

    /**
     * Once Qliro holds none of the children, the zero bundle line is left out too: alone it would be
     * a capture of nothing.
     */
    public function testSendsNoCaptureOfTheZeroBundleLineAlone(): void
    {
        [$bundle, $first, $second] = $this->bundlePricedByChildren(false);
        $info = [
            Config::QLIROONE_ADDITIONAL_INFO_RESERVED_DISCOUNT => [
                'products' => ['1:KIT' => 2, '2:KIT-A' => 1, '3:KIT-B' => 3],
                'discounts' => [],
            ],
            Config::QLIROONE_ADDITIONAL_INFO_CAPTURED_UNITS => ['1:KIT' => 1, '2:KIT-A' => 1, '3:KIT-B' => 3],
        ];

        self::assertSame([], $this->build([$bundle, $first, $second], [1 => 1.0], $info));
    }

    /**
     * Shipped separately, the children are rows of the shipment and go out once each.
     */
    public function testCapturesTheChildrenOfABundleShippedSeparatelyOnce(): void
    {
        [$bundle, $first, $second] = $this->bundlePricedByChildren(true);

        $lines = $this->build([$bundle, $first, $second], [1 => 1.0, 2 => 1.0, 3 => 3.0])[0]->getOrderItems();

        self::assertSame(
            [['1:KIT', 1.0], ['2:KIT-A', 1.0], ['3:KIT-B', 3.0]],
            array_map(static fn($line): array => [$line->getMerchantReference(), $line->getQuantity()], $lines)
        );
    }

    public function testRefusesToBuildWithoutAnOrder(): void
    {
        $this->expectException(\LogicException::class);

        $this->builder()->create();
    }

    /**
     * @param OrderItem[] $orderItems
     * @param array<int, float> $shippedQuantities Shipped quantity per order item id
     * @param array<string, mixed> $info Payment additional information
     * @return QliroShipment[]
     */
    private function build(array $orderItems, array $shippedQuantities, array $info = []): array
    {
        $builder = $this->builder();
        $builder->setShipment($this->shipment($this->order($orderItems, $info), $shippedQuantities));

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
    private function order(array $items, array $info = []): Order&MockObject
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getItemById',
                'getInvoiceCollection',
                'getShipmentsCollection',
                'getStoreId',
                'getPayment',
                'getAllItems',
            ])
            ->addMethods(['setFirstCaptureFlag'])
            ->getMock();

        // The lines go out as this version builds them, see LineReference::alignWithReservation
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturnCallback(static fn($key = null) => $info[$key] ?? true);

        $order->method('getPayment')->willReturn($payment);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getInvoiceCollection')->willReturn([]);
        $order->method('getShipmentsCollection')->willReturn([]);
        $order->method('getAllItems')->willReturn($items);
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

    /**
     * Two bundles ordered, each with one A and three B
     *
     * @return OrderItem[]
     */
    private function bundlePricedByChildren(bool $shipSeparately): array
    {
        $bundle = $this->orderItem('KIT', 2.0, [
            'id' => 1, 'type' => 'bundle', 'childrenCalculated' => true, 'shipSeparately' => $shipSeparately,
        ]);
        $first = $this->orderItem('KIT-A', 2.0, ['id' => 2, 'parentId' => 1, 'shipSeparately' => $shipSeparately]);
        $second = $this->orderItem('KIT-B', 6.0, ['id' => 3, 'parentId' => 1, 'shipSeparately' => $shipSeparately]);
        $bundle->method('getChildrenItems')->willReturn([$first, $second]);

        return [$bundle, $first, $second];
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
        $item->method('isChildrenCalculated')->willReturn($data['childrenCalculated'] ?? false);
        $item->method('isShipSeparately')->willReturn($data['shipSeparately'] ?? false);
        $item->method('getProduct')->willReturn($product);

        return $item;
    }
}
