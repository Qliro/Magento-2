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
use Qliro\QliroOne\Api\Data\AdminOrderPaymentTransactionInterface;
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

    /**
     * @var BundleChildren
     */
    private $bundleChildren;

    private const TYPE_CAPTURE = 'Capture';

    private const FAILED = [
        QliroOrderManagementStatusInterface::STATUS_ERROR,
        QliroOrderManagementStatusInterface::STATUS_CANCELLED,
    ];

    /**
     * @param OrderManagementStatusRepositoryInterface $statusRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param BundleChildren|null $bundleChildren
     */
    public function __construct(
        private readonly OrderManagementStatusRepositoryInterface $statusRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        ?BundleChildren $bundleChildren = null
    ) {
        $this->bundleChildren = $bundleChildren ?? new BundleChildren();
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
     * The capture Qliro holds for a document it refused as already shipped, null unless one fits.
     *
     * The refusal names the preauthorization, and AddItemsToInvoice refuses any id that is not a
     * capture, so only a capture Qliro has not failed counts. An invoice skips captures other
     * invoices carry, and of several left takes the one of the amount just sent, else the one of
     * its own amount. A capture of a discounted order need not be its invoice's amount: an order
     * level discount line goes whole with one capture, and a per unit discount is divided to the öre.
     *
     * @param AdminOrderPaymentTransactionInterface[] $transactions
     * @param Order $order
     * @param Invoice|null $invoice
     * @param float|null $sentTotal The total of the refused request, null when it is not known
     * @return int|null
     */
    public function captureAtQliro(
        array $transactions,
        Order $order,
        ?Invoice $invoice = null,
        ?float $sentTotal = null
    ): ?int {
        $spent = $invoice ? $this->invoiceTransactions($order, (int)$invoice->getId()) : [];
        $open = [];

        foreach ($transactions as $transaction) {
            $id = (int)$transaction->getPaymentTransactionId();

            if ($id && $transaction->getType() === self::TYPE_CAPTURE && !isset($spent[$id])
                && !in_array($transaction->getStatus(), self::FAILED, true)
            ) {
                $open[$id] = (float)$transaction->getAmount();
            }
        }

        if (count($open) > 1) {
            $open = $this->ofAmount($open, $sentTotal)
                ?? $this->ofAmount($open, $invoice ? (float)$invoice->getGrandTotal() : null)
                ?? [];
        }

        return count($open) === 1 ? (int)array_key_first($open) : null;
    }

    /**
     * The one capture of this amount, null when there is no amount or not exactly one
     *
     * @param array<int, float> $open
     * @param float|null $amount
     * @return array<int, float>|null
     */
    private function ofAmount(array $open, ?float $amount): ?array
    {
        if ($amount === null) {
            return null;
        }

        $matching = array_filter($open, fn (float $captured) => abs($captured - $amount) < 0.005);

        return count($matching) === 1 ? $matching : null;
    }

    /**
     * Whether a shipment capture of this order, one Qliro has not failed, holds this transaction
     *
     * @param Order $order
     * @param int $transactionId
     * @return bool
     */
    public function isShipmentCapture(Order $order, int $transactionId): bool
    {
        return in_array($transactionId, $this->capturedShipmentTransactions($order), true);
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
     * Quantity per order item that carries a price, see BundleChildren::chargedQuantities
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

            if (!$orderItem || $qty <= 0) {
                continue;
            }

            $itemId = (int)$orderItem->getId();
            $quantities[$itemId] = ($quantities[$itemId] ?? 0.0) + $qty;
        }

        return $this->bundleChildren->chargedQuantities($quantities, $order);
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
