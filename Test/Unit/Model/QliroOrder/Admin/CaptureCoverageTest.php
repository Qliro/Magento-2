<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Admin;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\Shipment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusInterface;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Api\OrderManagementStatusSearchResultInterface;
use Qliro\QliroOne\Model\OrderManagementStatus;
use Qliro\QliroOne\Model\QliroOrder\Admin\CaptureCoverage;

/**
 * Both capture triggers are on by default, and a shipment and an invoice created in separate requests
 * each sent a capture. What the other trigger captured is read from what is saved, not from a flag.
 *
 * @see \Qliro\QliroOne\Model\QliroOrder\Admin\CaptureCoverage
 */
class CaptureCoverageTest extends TestCase
{
    /** @var array<int, array{qtyInvoiced: float, qtyShipped: float, parent: int|null}> */
    private array $orderItems = [];

    /** @var Shipment[] */
    private array $shipments = [];

    /** @var Invoice[] */
    private array $invoices = [];

    /** @var OrderManagementStatus[] newest first, as the repository is asked for them */
    private array $statusRows = [];

    protected function setUp(): void
    {
        $this->orderItems = [];
        $this->shipments = [];
        $this->invoices = [];
        $this->statusRows = [];
    }

    // ---- an invoice after a shipment ----------------------------------------------------------

    /**
     * The reported case: the shipment captured both lines and shipping, the invoice of the same lines
     * follows in its own request
     */
    public function testAnInvoiceOfWhatACapturedShipmentShippedTakesItsTransaction(): void
    {
        $this->item(1, invoiced: 1, shipped: 1);
        $this->item(2, invoiced: 1, shipped: 1);
        $this->shipments[] = $this->shipment(5, [1 => 1, 2 => 1]);
        $this->statusRow(5, 331743203, QliroOrderManagementStatusInterface::STATUS_CREATED);

        self::assertSame(331743203, $this->coverage()->shipmentCaptureFor($this->invoice(9, [1 => 1, 2 => 1]), $this->order()));
    }

    public function testAShipmentWithoutACaptureCoversNothing(): void
    {
        $this->item(1, invoiced: 1, shipped: 1);
        $this->shipments[] = $this->shipment(5, [1 => 1]);

        self::assertNull($this->coverage()->shipmentCaptureFor($this->invoice(9, [1 => 1]), $this->order()));
    }

    /**
     * The newest row is what Qliro last said: a capture it failed moved no money
     */
    public function testAShipmentCaptureQliroFailedCoversNothing(): void
    {
        $this->item(1, invoiced: 1, shipped: 1);
        $this->shipments[] = $this->shipment(5, [1 => 1]);
        $this->statusRow(5, 331743203, QliroOrderManagementStatusInterface::STATUS_ERROR);
        $this->statusRow(5, 331743203, QliroOrderManagementStatusInterface::STATUS_CREATED);

        self::assertNull($this->coverage()->shipmentCaptureFor($this->invoice(9, [1 => 1]), $this->order()));
    }

    public function testAShipmentCaptureQliroConfirmedCovers(): void
    {
        $this->item(1, invoiced: 1, shipped: 1);
        $this->shipments[] = $this->shipment(5, [1 => 1]);
        $this->statusRow(5, 331743203, QliroOrderManagementStatusInterface::STATUS_SUCCESS);
        $this->statusRow(5, 331743203, QliroOrderManagementStatusInterface::STATUS_CREATED);

        self::assertSame(331743203, $this->coverage()->shipmentCaptureFor($this->invoice(9, [1 => 1]), $this->order()));
    }

    /**
     * Partly covered is left to its own capture, so nothing is left uncaptured on a guess
     */
    public function testAnInvoiceOfMoreThanWasShippedIsNotCovered(): void
    {
        $this->item(1, invoiced: 1, shipped: 1);
        $this->item(2, invoiced: 1, shipped: 0);
        $this->shipments[] = $this->shipment(5, [1 => 1]);
        $this->statusRow(5, 331743203, QliroOrderManagementStatusInterface::STATUS_CREATED);

        self::assertNull($this->coverage()->shipmentCaptureFor($this->invoice(9, [1 => 1, 2 => 1]), $this->order()));
    }

    /**
     * Two units shipped and captured at once, invoiced one at a time: the first invoice takes the
     * capture, the second finds it spent and makes its own, which Qliro answers as already shipped
     */
    public function testAShipmentCaptureAnInvoiceAlreadyTookIsSpent(): void
    {
        $this->item(1, invoiced: 1, shipped: 2);
        $this->shipments[] = $this->shipment(5, [1 => 2]);
        $this->statusRow(5, 331743203, QliroOrderManagementStatusInterface::STATUS_CREATED);

        self::assertSame(331743203, $this->coverage()->shipmentCaptureFor($this->invoice(9, [1 => 1]), $this->order()));

        $this->invoices[] = $this->invoice(9, [1 => 1], transactionId: '331743203');

        self::assertNull($this->coverage()->shipmentCaptureFor($this->invoice(10, [1 => 1]), $this->order()));
    }

    /**
     * Shipped and invoiced in turn: each invoice takes the capture of the shipment no invoice took yet
     */
    public function testEachInvoiceTakesTheShipmentCaptureNotYetTaken(): void
    {
        $this->item(1, invoiced: 2, shipped: 2);
        $this->shipments[] = $this->shipment(5, [1 => 1]);
        $this->shipments[] = $this->shipment(6, [1 => 1]);
        $this->statusRow(6, 331743300, QliroOrderManagementStatusInterface::STATUS_CREATED);
        $this->statusRow(5, 331743203, QliroOrderManagementStatusInterface::STATUS_SUCCESS);
        $this->invoices[] = $this->invoice(9, [1 => 1], transactionId: '331743203');

        self::assertSame(331743300, $this->coverage()->shipmentCaptureFor($this->invoice(10, [1 => 1]), $this->order()));
    }

    /**
     * A payment records one transaction per invoice, so an invoice spanning two captures makes its own
     */
    public function testAnInvoiceSpanningTwoShipmentCapturesIsNotCovered(): void
    {
        $this->item(1, invoiced: 1, shipped: 1);
        $this->item(2, invoiced: 1, shipped: 1);
        $this->shipments[] = $this->shipment(5, [1 => 1]);
        $this->shipments[] = $this->shipment(6, [2 => 1]);
        $this->statusRow(6, 331743300, QliroOrderManagementStatusInterface::STATUS_CREATED);
        $this->statusRow(5, 331743203, QliroOrderManagementStatusInterface::STATUS_CREATED);

        self::assertNull($this->coverage()->shipmentCaptureFor($this->invoice(9, [1 => 1, 2 => 1]), $this->order()));
    }

    /**
     * An invoice carries a configurable's child line too, a shipment only the parent
     */
    public function testAChildLineFollowsItsParent(): void
    {
        $this->item(1, invoiced: 1, shipped: 1);
        $this->item(2, invoiced: 1, shipped: 0, parent: 1);
        $this->shipments[] = $this->shipment(5, [1 => 1]);
        $this->statusRow(5, 331743203, QliroOrderManagementStatusInterface::STATUS_CREATED);

        self::assertSame(331743203, $this->coverage()->shipmentCaptureFor($this->invoice(9, [1 => 1, 2 => 1]), $this->order()));
    }

    // ---- a shipment after an invoice ----------------------------------------------------------

    public function testAShipmentOfWhatAnOnlineInvoiceCapturedIsCovered(): void
    {
        $this->item(1, invoiced: 1, shipped: 1);
        $this->invoices[] = $this->invoice(9, [1 => 1], transactionId: '331743203');

        self::assertTrue($this->coverage()->isCoveredByInvoices($this->shipment(5, [1 => 1]), $this->order()));
    }

    /**
     * An invoice captured offline moved no money at Qliro, so the shipment still captures
     */
    public function testAnOfflineInvoiceCoversNothing(): void
    {
        $this->item(1, invoiced: 1, shipped: 1);
        $this->invoices[] = $this->invoice(9, [1 => 1]);

        self::assertFalse($this->coverage()->isCoveredByInvoices($this->shipment(5, [1 => 1]), $this->order()));
    }

    public function testACancelledInvoiceCoversNothing(): void
    {
        $this->item(1, invoiced: 0, shipped: 1);
        $this->invoices[] = $this->invoice(9, [1 => 1], transactionId: '331743203', state: Invoice::STATE_CANCELED);

        self::assertFalse($this->coverage()->isCoveredByInvoices($this->shipment(5, [1 => 1]), $this->order()));
    }

    /**
     * The invoice Qliro's confirmation of an earlier shipment created covers that shipment, not this one
     */
    public function testAnInvoiceOfAnEarlierShipmentDoesNotCoverTheNextOne(): void
    {
        $this->item(1, invoiced: 1, shipped: 2);
        $this->shipments[] = $this->shipment(5, [1 => 1]);
        $this->invoices[] = $this->invoice(9, [1 => 1], transactionId: '331743203');

        self::assertFalse($this->coverage()->isCoveredByInvoices($this->shipment(6, [1 => 1]), $this->order()));
    }

    /**
     * Invoiced with Capture Online while capture on invoice is off: Magento names the transaction
     * itself and nothing reached Qliro, so the shipment still captures
     */
    public function testAnInvoiceWhoseCaptureNeverReachedQliroCoversNothing(): void
    {
        $this->item(1, invoiced: 1, shipped: 1);
        $this->invoices[] = $this->invoice(9, [1 => 1], transactionId: 'qliroone-283369012-capture');

        self::assertFalse($this->coverage()->isCoveredByInvoices($this->shipment(5, [1 => 1]), $this->order()));
    }

    /**
     * A shipment saved through the repository leaves qty_shipped alone, the shipments themselves count
     */
    public function testAnUnregisteredEarlierShipmentStillCounts(): void
    {
        $this->item(1, invoiced: 1, shipped: 0);
        $this->shipments[] = $this->shipment(5, [1 => 1]);
        $this->invoices[] = $this->invoice(9, [1 => 1], transactionId: '331743203');

        self::assertFalse($this->coverage()->isCoveredByInvoices($this->shipment(6, [1 => 1]), $this->order()));
    }

    // ---- harness ------------------------------------------------------------------------------

    private function coverage(): CaptureCoverage
    {
        $result = $this->createMock(OrderManagementStatusSearchResultInterface::class);
        $result->method('getItems')->willReturnCallback(fn () => $this->statusRows);

        $repository = $this->createMock(OrderManagementStatusRepositoryInterface::class);
        $repository->method('getList')->willReturn($result);

        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnSelf();
        $builder->method('addSortOrder')->willReturnSelf();
        $builder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        return new CaptureCoverage($repository, $builder);
    }

    private function item(int $id, float $invoiced, float $shipped, ?int $parent = null): void
    {
        $this->orderItems[$id] = ['qtyInvoiced' => $invoiced, 'qtyShipped' => $shipped, 'parent' => $parent];
    }

    private function order(): Order&MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getShipmentsCollection')->willReturnCallback(fn () => $this->shipments);
        $order->method('getInvoiceCollection')->willReturnCallback(fn () => $this->invoices);
        $order->method('getItemById')->willReturnCallback(
            function ($id) {
                if (!isset($this->orderItems[$id])) {
                    return null;
                }

                $data = $this->orderItems[$id];
                $item = $this->createMock(OrderItem::class);
                $item->method('getId')->willReturn($id);
                $item->method('getParentItemId')->willReturn($data['parent']);
                $item->method('getQtyInvoiced')->willReturn($data['qtyInvoiced']);
                $item->method('getQtyShipped')->willReturn($data['qtyShipped']);

                return $item;
            }
        );

        return $order;
    }

    /**
     * @param array<int, float> $quantities per order item id
     */
    private function shipment(int $id, array $quantities): Shipment&MockObject
    {
        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getId')->willReturn($id);
        $shipment->method('getAllItems')->willReturn($this->documentItems(Shipment\Item::class, $quantities));

        return $shipment;
    }

    /**
     * @param array<int, float> $quantities per order item id
     */
    private function invoice(int $id, array $quantities, ?string $transactionId = null, int $state = Invoice::STATE_PAID): Invoice&MockObject
    {
        $invoice = $this->createMock(Invoice::class);
        $invoice->method('getId')->willReturn($id);
        $invoice->method('getTransactionId')->willReturn($transactionId);
        $invoice->method('getState')->willReturn($state);
        $invoice->method('getAllItems')->willReturn($this->documentItems(Invoice\Item::class, $quantities));

        return $invoice;
    }

    /**
     * @param class-string $class
     * @param array<int, float> $quantities
     * @return array<int, MockObject>
     */
    private function documentItems(string $class, array $quantities): array
    {
        $items = [];

        foreach ($quantities as $orderItemId => $qty) {
            $item = $this->createMock($class);
            $item->method('getOrderItemId')->willReturn($orderItemId);
            $item->method('getQty')->willReturn($qty);
            $items[] = $item;
        }

        return $items;
    }

    private function statusRow(int $shipmentId, int $transactionId, string $status): void
    {
        $row = $this->createMock(OrderManagementStatus::class);
        $row->method('getRecordId')->willReturn($shipmentId);
        $row->method('getTransactionId')->willReturn($transactionId);
        $row->method('getTransactionStatus')->willReturn($status);
        $this->statusRows[] = $row;
    }
}
