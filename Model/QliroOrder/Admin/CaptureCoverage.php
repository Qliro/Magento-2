<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Shipment;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterface;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusInterface;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;

/**
 * Whether the other capture trigger already captured what a document is about to capture.
 *
 * capture_on_shipment and capture_on_invoice are both on by default, and a store whose shipment and
 * invoice are created in separate requests, an ERP or a shipping integration, sent the capture twice:
 * the per-request flag of PLIN-381 cannot see the other request. This answers from what is saved
 * instead: the OM status rows of the shipment captures, and the invoices that carry a Qliro capture.
 *
 * Anything it cannot match exactly is left to the capture the document has always made, so nothing
 * is ever left uncaptured by a guess.
 */
class CaptureCoverage
{
    private const EPSILON = 0.000001;

    private const FAILED = [
        QliroOrderManagementStatusInterface::STATUS_ERROR,
        QliroOrderManagementStatusInterface::STATUS_CANCELLED,
    ];

    /**
     * @param OrderManagementStatusRepositoryInterface $statusRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     */
    public function __construct(
        private readonly OrderManagementStatusRepositoryInterface $statusRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * The transaction of the one shipment capture that covers this invoice, null when none does.
     *
     * A shipment capture no invoice has taken yet, which ships at least every line of this invoice.
     * One that an invoice already carries is spent, and an invoice spanning two captures is left to
     * its own capture, since an invoice records one transaction.
     *
     * @param Invoice $invoice
     * @param Order $order
     * @return int|null
     */
    public function shipmentCaptureFor(Invoice $invoice, Order $order): ?int
    {
        $invoiced = $this->documentQuantities($invoice, $order);

        if ($invoiced === []) {
            return null;
        }

        $transactions = $this->capturedShipmentTransactions($order);
        $spent = $this->invoiceTransactions($order, (int)$invoice->getId());

        // The oldest that fits, the next invoice then finds the one after it
        foreach ($order->getShipmentsCollection() as $shipment) {
            $transaction = $transactions[(int)$shipment->getId()] ?? null;

            if ($transaction !== null && !isset($spent[$transaction])
                && $this->covers($this->documentQuantities($shipment, $order), $invoiced)
            ) {
                return $transaction;
            }
        }

        return null;
    }

    /**
     * Whether invoices that carry a Qliro capture already cover everything this shipment ships
     *
     * @param Shipment $shipment
     * @param Order $order
     * @return bool
     */
    public function isCoveredByInvoices(Shipment $shipment, Order $order): bool
    {
        $shipped = $this->documentQuantities($shipment, $order);

        if ($shipped === []) {
            return false;
        }

        $captured = [];

        foreach ($order->getInvoiceCollection() as $invoice) {
            if (!$this->isQliroCapture($invoice)) {
                continue;
            }

            $this->add($captured, $this->documentQuantities($invoice, $order));
        }

        // Counted from the shipments themselves, not qty_shipped: a shipment saved through the
        // repository is not registered and never raises it
        $shippedBefore = [];

        foreach ($order->getShipmentsCollection() as $earlier) {
            if ((int)$earlier->getId() !== (int)$shipment->getId()) {
                $this->add($shippedBefore, $this->documentQuantities($earlier, $order));
            }
        }

        foreach ($shipped as $itemId => $qty) {
            if (($captured[$itemId] ?? 0.0) - ($shippedBefore[$itemId] ?? 0.0) + self::EPSILON < $qty) {
                return false;
            }
        }

        return true;
    }

    /**
     * Qliro names a capture by a number. Magento gives an invoice whose capture did not reach Qliro
     * an id of its own, qliroone-<id>-capture, and an offline one none at all.
     *
     * @param Invoice $invoice
     * @return bool
     */
    private function isQliroCapture(Invoice $invoice): bool
    {
        return (int)$invoice->getState() !== Invoice::STATE_CANCELED
            && ctype_digit((string)$invoice->getTransactionId());
    }

    /**
     * The Qliro capture each other invoice of this order carries, as a set
     *
     * @param Order $order
     * @param int $exceptInvoiceId
     * @return array<int, true>
     */
    private function invoiceTransactions(Order $order, int $exceptInvoiceId): array
    {
        $transactions = [];

        foreach ($order->getInvoiceCollection() as $invoice) {
            if (($exceptInvoiceId && (int)$invoice->getId() === $exceptInvoiceId) || !$this->isQliroCapture($invoice)) {
                continue;
            }

            $transactions[(int)$invoice->getTransactionId()] = true;
        }

        return $transactions;
    }

    /**
     * @param array<int, float> $captured
     * @param array<int, float> $wanted
     * @return bool
     */
    private function covers(array $captured, array $wanted): bool
    {
        foreach ($wanted as $itemId => $qty) {
            if (($captured[$itemId] ?? 0.0) + self::EPSILON < $qty) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, float> $total
     * @param array<int, float> $quantities
     * @return void
     */
    private function add(array &$total, array $quantities): void
    {
        foreach ($quantities as $itemId => $qty) {
            $total[$itemId] = ($total[$itemId] ?? 0.0) + $qty;
        }
    }

    /**
     * Quantity per top level order item, children follow their parent in both documents
     *
     * @param Invoice|Shipment $document
     * @param Order $order
     * @return array<int, float>
     */
    private function documentQuantities($document, Order $order): array
    {
        $quantities = [];

        foreach ($document->getAllItems() as $item) {
            $orderItem = $order->getItemById($item->getOrderItemId());
            $qty = (float)$item->getQty();

            if (!$orderItem || $orderItem->getParentItemId() || $qty <= 0) {
                continue;
            }

            $itemId = (int)$orderItem->getId();
            $quantities[$itemId] = ($quantities[$itemId] ?? 0.0) + $qty;
        }

        return $quantities;
    }

    /**
     * Transaction per shipment id, for the shipments of this order whose capture Qliro has not failed
     *
     * @param Order $order
     * @return array<int, int>
     */
    private function capturedShipmentTransactions(Order $order): array
    {
        $shipmentIds = [];

        foreach ($order->getShipmentsCollection() as $shipment) {
            if ($shipment->getId()) {
                $shipmentIds[] = (int)$shipment->getId();
            }
        }

        if ($shipmentIds === []) {
            return [];
        }

        $search = $this->searchCriteriaBuilder
            ->addFilter(OrderManagementStatusInterface::FIELD_RECORD_TYPE, OrderManagementStatusInterface::RECORD_TYPE_SHIPMENT)
            ->addFilter(OrderManagementStatusInterface::FIELD_RECORD_ID, $shipmentIds, 'in')
            ->addSortOrder(new SortOrder([
                SortOrder::FIELD => OrderManagementStatusInterface::FIELD_ID,
                SortOrder::DIRECTION => SortOrder::SORT_DESC,
            ]))
            ->create();

        $latest = [];

        foreach ($this->statusRepository->getList($search)->getItems() as $row) {
            $shipmentId = (int)$row->getRecordId();

            // Newest first, so the first row of a shipment carries what Qliro last said about it
            if (!isset($latest[$shipmentId])) {
                $latest[$shipmentId] = $row;
            }
        }

        $transactions = [];

        foreach ($latest as $shipmentId => $row) {
            if ($row->getTransactionId() && !in_array($row->getTransactionStatus(), self::FAILED, true)) {
                $transactions[$shipmentId] = (int)$row->getTransactionId();
            }
        }

        return $transactions;
    }
}
