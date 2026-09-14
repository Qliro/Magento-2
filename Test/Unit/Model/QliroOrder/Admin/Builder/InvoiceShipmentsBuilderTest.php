<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Admin\Builder;

use Magento\Catalog\Model\Product;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Invoice\Item as InvoiceItem;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Admin\Builder\OrderItemHandlerInterface;
use Qliro\QliroOne\Api\Data\QliroShipmentInterfaceFactory;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterfaceFactory;
use Qliro\QliroOne\Helper\Data as QliroHelper;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Product\ProductPool;
use Qliro\QliroOne\Model\Product\Type\Handler\BundleHandler;
use Qliro\QliroOne\Model\Product\Type\Handler\ConfigurableHandler;
use Qliro\QliroOne\Model\Product\Type\Handler\DefaultHandler;
use Qliro\QliroOne\Model\Product\Type\OrderSourceProvider;
use Qliro\QliroOne\Model\Product\Type\TypePoolHandler;
use Qliro\QliroOne\Model\Product\Type\TypeResolver;
use Qliro\QliroOne\Model\Product\Type\TypeSourceItem;
use Qliro\QliroOne\Model\Product\VatRate;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\InvoiceShipmentsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\LineVatRate;
use Qliro\QliroOne\Model\QliroOrder\Shipment;

/**
 * The lines a capture is sent to Qliro as, built from the invoice through the same type pool the
 * checkout builds the order with. The quantity decides what the buyer is charged now and what is
 * left to capture later, so it is pinned alongside the amounts.
 *
 * @see \Qliro\QliroOne\Model\QliroOrder\Admin\Builder\InvoiceShipmentsBuilder
 */
class InvoiceShipmentsBuilderTest extends TestCase
{
    /**
     * A partial capture carries the quantity being invoiced now, at the amounts of the order line.
     */
    public function testCapturesTheQuantityBeingInvoicedAtTheAmountsOfTheOrderLine(): void
    {
        $item = $this->orderItem(['id' => 1, 'sku' => 'SKU-1', 'name' => 'Simple', 'type' => 'simple',
            'qtyOrdered' => 5.0, 'incVat' => 125.0, 'exVat' => 100.0, 'taxPercent' => 25.0]);

        $lines = $this->build([$item], [1 => 2.0])[0]->getOrderItems();

        self::assertCount(1, $lines);
        self::assertSame(2.0, $lines[0]->getQuantity());
        self::assertSame(125.0, $lines[0]->getPricePerItemIncVat());
        self::assertSame(100.0, $lines[0]->getPricePerItemExVat());
        self::assertSame(25.0, $lines[0]->getVatRate());
    }

    /**
     * A configurable is captured as one line, the child's, and it carries the quantity the parent
     * is invoiced at. The child of a configurable is invoiced at its own quantity in Magento, and
     * charging that would capture the wrong number of items.
     */
    public function testCapturesAConfigurableAtTheQuantityOfItsParent(): void
    {
        $parent = $this->orderItem(['id' => 1, 'sku' => 'SHIRT', 'name' => 'Shirt', 'type' => 'configurable',
            'qtyOrdered' => 4.0, 'incVat' => 250.0, 'exVat' => 200.0, 'taxPercent' => 25.0]);
        $child = $this->orderItem(['id' => 2, 'sku' => 'SHIRT-BLUE', 'name' => 'Shirt blue', 'type' => 'simple',
            'qtyOrdered' => 4.0, 'incVat' => 0.0, 'exVat' => 0.0, 'taxPercent' => 25.0, 'parentId' => 1], $parent);

        // Magento invoices the child at its own quantity, four, and the parent at two
        $lines = $this->build([$parent, $child], [1 => 2.0, 2 => 4.0])[0]->getOrderItems();

        self::assertCount(1, $lines);
        self::assertSame(2.0, $lines[0]->getQuantity());
        self::assertSame(250.0, $lines[0]->getPricePerItemIncVat());
        self::assertSame(200.0, $lines[0]->getPricePerItemExVat());
    }

    /**
     * A child whose parent is not part of this invoice is not captured on its own.
     */
    public function testLeavesOutAChildWhoseParentIsNotBeingInvoiced(): void
    {
        $parent = $this->orderItem(['id' => 1, 'sku' => 'SHIRT', 'name' => 'Shirt', 'type' => 'configurable',
            'qtyOrdered' => 4.0, 'incVat' => 250.0, 'exVat' => 200.0, 'taxPercent' => 25.0]);
        $child = $this->orderItem(['id' => 2, 'sku' => 'SHIRT-BLUE', 'name' => 'Shirt blue', 'type' => 'simple',
            'qtyOrdered' => 4.0, 'incVat' => 0.0, 'exVat' => 0.0, 'taxPercent' => 25.0, 'parentId' => 1], $parent);

        self::assertSame([], $this->build([$parent, $child], [2 => 2.0]));
    }

    /**
     * A line invoiced at nothing is not captured, and an invoice that captures no line at all
     * sends no shipment rather than an empty one.
     */
    public function testSendsNoShipmentWhenNothingIsBeingInvoiced(): void
    {
        $item = $this->orderItem(['id' => 1, 'sku' => 'SKU-1', 'name' => 'Simple', 'type' => 'simple',
            'qtyOrdered' => 5.0, 'incVat' => 125.0, 'exVat' => 100.0, 'taxPercent' => 25.0]);

        self::assertSame([], $this->build([$item], [1 => 0.0]));
    }

    /**
     * The handlers run over the captured lines, which is where the shipping fee, the invoice fee
     * and the discount come from.
     */
    public function testRunsTheHandlersOverTheCapturedLines(): void
    {
        $item = $this->orderItem(['id' => 1, 'sku' => 'SKU-1', 'name' => 'Simple', 'type' => 'simple',
            'qtyOrdered' => 1.0, 'incVat' => 125.0, 'exVat' => 100.0, 'taxPercent' => 25.0]);

        $handler = $this->createMock(OrderItemHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(static function (array $lines): array {
            $lines[] = (new Item())->setMerchantReference('shipping')->setPricePerItemIncVat(49.0);

            return $lines;
        });

        $lines = $this->build([$item], [1 => 1.0], [$handler, new \stdClass()])[0]->getOrderItems();

        self::assertCount(2, $lines);
        self::assertSame('shipping', $lines[1]->getMerchantReference());
    }

    /**
     * Only the first invoice of an order carries the shipping and the fee, and the handlers read
     * that off the order. A second invoice would charge the delivery twice.
     */
    public function testMarksTheFirstInvoiceOfAnOrderAsTheFirstCapture(): void
    {
        $item = $this->orderItem(['id' => 1, 'sku' => 'SKU-1', 'name' => 'Simple', 'type' => 'simple',
            'qtyOrdered' => 2.0, 'incVat' => 125.0, 'exVat' => 100.0, 'taxPercent' => 25.0]);

        $first = $this->order([$item], ['invoice-1']);
        $first->expects(self::once())->method('setFirstCaptureFlag')->with(true);
        $firstBuilder = $this->builder();
        $firstBuilder->setPayment($this->payment($first, $this->invoice('invoice-1', [1 => 1.0])));
        $firstBuilder->create();

        $second = $this->order([$item], ['invoice-1', 'invoice-2']);
        $second->expects(self::never())->method('setFirstCaptureFlag');
        $secondBuilder = $this->builder();
        $secondBuilder->setPayment($this->payment($second, $this->invoice('invoice-2', [1 => 1.0])));
        $secondBuilder->create();
    }

    /**
     * The builder is one shared instance, so it releases the invoice it built from: the next
     * capture must not send the lines of the one before it.
     */
    public function testReleasesTheInvoiceItBuiltFrom(): void
    {
        $item = $this->orderItem(['id' => 1, 'sku' => 'SKU-1', 'name' => 'Simple', 'type' => 'simple',
            'qtyOrdered' => 1.0, 'incVat' => 125.0, 'exVat' => 100.0, 'taxPercent' => 25.0]);

        $builder = $this->builder();
        $builder->setPayment($this->payment($this->order([$item]), $this->invoice('invoice-1', [1 => 1.0])));
        $builder->create();

        $this->expectException(\LogicException::class);
        $builder->create();
    }

    public function testRefusesToBuildWithoutAnOrder(): void
    {
        $this->expectException(\LogicException::class);

        $this->builder()->create();
    }

    /**
     * The source provider is told the order is gone as well, which is what drops the lines it
     * read. Two invoices can be captured in one request, and the second would otherwise be built
     * from the lines of the first.
     */
    public function testTellsTheSourceProviderTheOrderIsGone(): void
    {
        $item = $this->orderItem(['id' => 1, 'sku' => 'SKU-1', 'name' => 'Simple', 'type' => 'simple',
            'qtyOrdered' => 1.0, 'incVat' => 125.0, 'exVat' => 100.0, 'taxPercent' => 25.0]);

        $provider = $this->createMock(OrderSourceProvider::class);
        $provider->expects(self::once())->method('setOrder')->with(null);
        $provider->method('generateSourceItem')->willReturn($this->sourceItem());

        $builder = $this->builder([], $provider);
        $builder->setPayment($this->payment($this->order([$item]), $this->invoice('invoice-1', [1 => 1.0])));
        $builder->create();
    }

    /**
     * @param OrderItem[] $orderItems
     * @param float[] $invoicedQuantities Invoiced quantity per order item id
     * @param object[] $handlers
     * @return Shipment[]
     */
    private function build(array $orderItems, array $invoicedQuantities, array $handlers = []): array
    {
        $builder = $this->builder($handlers);
        $builder->setPayment($this->payment(
            $this->order($orderItems),
            $this->invoice('invoice-1', $invoicedQuantities)
        ));

        return $builder->create();
    }

    /**
     * @param object[] $handlers
     */
    private function builder(array $handlers = [], ?OrderSourceProvider $sourceProvider = null): InvoiceShipmentsBuilder
    {
        $shipmentFactory = $this->createMock(QliroShipmentInterfaceFactory::class);
        $shipmentFactory->method('create')->willReturnCallback(static fn(): Shipment => new Shipment());

        $sourceItemFactory = $this->createMock(TypeSourceItemInterfaceFactory::class);
        $sourceItemFactory->method('create')->willReturnCallback(static fn(): TypeSourceItem => new TypeSourceItem());

        return new InvoiceShipmentsBuilder(
            $this->typePool(),
            $shipmentFactory,
            $sourceProvider ?? new OrderSourceProvider($this->createMock(ProductPool::class), $sourceItemFactory),
            $handlers
        );
    }

    /**
     * The pool as `etc/di.xml` wires it, see OrderItemsBuilderTest
     */
    private function typePool(): TypePoolHandler
    {
        $default = $this->typeHandler(DefaultHandler::class);
        $configurable = $this->typeHandler(ConfigurableHandler::class);
        $bundle = $this->typeHandler(BundleHandler::class);

        return new TypePoolHandler($this->createMock(TypeResolver::class), [
            'virtual' => $default,
            'simple' => $default,
            'virtual:configurable' => $configurable,
            'simple:configurable' => $configurable,
            'configurable' => null,
            'virtual:bundle' => $bundle,
            'simple:bundle' => $bundle,
            'bundle' => $bundle,
        ]);
    }

    /**
     * @param class-string<DefaultHandler> $class
     */
    private function typeHandler(string $class): DefaultHandler
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

        return new $class($itemFactory, $qliroHelper, $config, $vatRate, new LineVatRate());
    }

    /**
     * A read order line, the shape the provider hands to the type pool
     */
    private function sourceItem(): TypeSourceItem
    {
        $product = $this->createMock(Product::class);
        $product->method('getTypeId')->willReturn('simple');
        $product->method('getStoreId')->willReturn(1);

        return (new TypeSourceItem())
            ->setId(1)
            ->setName('Simple')
            ->setSku('SKU-1')
            ->setType('simple')
            ->setQty(1.0)
            ->setPriceInclTax(125.0)
            ->setPriceExclTax(100.0)
            ->setProduct($product)
            ->setItem(null);
    }

    private function payment(Order $order, Invoice $invoice): Payment&MockObject
    {
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrder'])
            ->addMethods(['getInvoice'])
            ->getMock();

        $payment->method('getOrder')->willReturn($order);
        $payment->method('getInvoice')->willReturn($invoice);

        return $payment;
    }

    /**
     * @param OrderItem[] $items
     * @param string[] $invoiceIds The invoices the order already carries
     */
    private function order(array $items, array $invoiceIds = ['invoice-1']): Order&MockObject
    {
        $invoices = [];

        foreach ($invoiceIds as $invoiceId) {
            $invoices[] = $this->invoice($invoiceId, []);
        }

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getItemById', 'getInvoiceCollection', 'getStoreId'])
            ->addMethods(['setFirstCaptureFlag'])
            ->getMock();

        $order->method('getStoreId')->willReturn(1);
        $order->method('getInvoiceCollection')->willReturn($invoices);
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
     * @param float[] $quantities Invoiced quantity per order item id
     */
    private function invoice(string $invoiceId, array $quantities): Invoice&MockObject
    {
        $items = [];

        foreach ($quantities as $orderItemId => $qty) {
            $item = $this->createMock(InvoiceItem::class);
            $item->method('getOrderItemId')->willReturn($orderItemId);
            $item->method('getQty')->willReturn($qty);
            $items[] = $item;
        }

        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getId')->willReturn($invoiceId);
        $invoice->method('getAllItems')->willReturn($items);

        return $invoice;
    }

    private function orderItem(array $data, ?OrderItem $parent = null): OrderItem&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getTypeId')->willReturn($data['type']);
        $product->method('getStoreId')->willReturn(1);

        $item = $this->createMock(OrderItem::class);
        $item->method('getId')->willReturn($data['id']);
        $item->method('getQuoteItemId')->willReturn($data['id']);
        $item->method('getSku')->willReturn($data['sku']);
        $item->method('getName')->willReturn($data['name']);
        $item->method('getProductType')->willReturn($data['type']);
        $item->method('getQtyOrdered')->willReturn($data['qtyOrdered']);
        $item->method('getPriceInclTax')->willReturn($data['incVat']);
        $item->method('getPrice')->willReturn($data['exVat']);
        $item->method('getTaxPercent')->willReturn($data['taxPercent']);
        $item->method('getParentItemId')->willReturn($data['parentId'] ?? null);
        $item->method('getParentItem')->willReturn($parent);
        $item->method('getProduct')->willReturn($product);

        return $item;
    }
}
