<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin\Builder;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Invoice\Item;
use Magento\Sales\Model\Order\Payment;
use Qliro\QliroOne\Api\Admin\Builder\OrderItemHandlerInterface;
use Qliro\QliroOne\Api\Data\QliroShipmentInterface;
use Qliro\QliroOne\Model\Product\Type\OrderSourceProvider;
use Qliro\QliroOne\Model\Product\Type\TypePoolHandler;
use Qliro\QliroOne\Api\Data\QliroShipmentInterfaceFactory as QliroShipmentFactory;

/**
 * QliroOne Admin Order shipments builder class
 */
class InvoiceShipmentsBuilder
{
    private ?Payment $payment = null;
    private ?Order $order = null;
    private ?Invoice $invoice = null;
    private array $handlers = [];

    /**
     * Class constructor
     *
     * @param TypePoolHandler                 $typeResolver
     * @param QliroShipmentFactory            $qliroShipmentFactory
     * @param OrderSourceProvider             $orderSourceProvider
     * @param OrderItemHandlerInterface[]     $handlers
     */
    public function __construct(
        private readonly TypePoolHandler      $typeResolver,
        private readonly QliroShipmentFactory $qliroShipmentFactory,
        private readonly OrderSourceProvider  $orderSourceProvider,
        array $handlers = []
    ) {
        $this->handlers = $handlers;
    }

    /**
     * @param Payment $payment
     */
    public function setPayment(Payment $payment): void
    {
        $this->payment = $payment;

        /** @var Order $order */
        $this->order = $this->payment->getOrder();

        /** @var  Invoice $invoice */
        $this->invoice = $this->payment->getInvoice();
    }

    /**
     * Create an array of containers
     *
     * @return QliroShipmentInterface[]
     */
    public function create(): array
    {
        if (empty($this->order)) {
            throw new \LogicException('Order entity is not set.');
        }

        // Snapshot the iterator so we can traverse it twice without re-fetching.
        $invoiceItems = iterator_to_array($this->invoice->getAllItems(), false);

        // Pass 1: build the configurable PARENT quantity map up front. Doing this in a
        // separate pass makes the main loop order-independent — a child variant line that
        // appears BEFORE its configurable parent line in the input list still resolves
        // correctly, where the previous single-pass logic silently dropped it (`continue`).
        $configurableProducts = $this->buildConfigurableQuantityMap($invoiceItems);

        // Pass 2: build the Qliro order items.
        $shipmentOrderItems = [];
        /** @var Item $invoiceItem */
        foreach ($invoiceItems as $invoiceItem) {
            $orderItem  = $this->resolveOrderItem($invoiceItem);
            $invoiceQty = (int)$invoiceItem->getQty();

            // For configurable children, use the parent's invoice qty regardless of where
            // the parent appeared in the iteration.
            if ($orderItem->getParentItemId()) {
                if (!isset($configurableProducts[$orderItem->getParentItemId()])) {
                    // Child belongs to a configurable that isn't being invoiced — skip,
                    // mirroring the old behaviour for that legitimate case.
                    continue;
                }
                $invoiceQty = $configurableProducts[$orderItem->getParentItemId()];
            }

            if (!$invoiceQty) {
                continue;
            }

            // Defensive over-qty check: the user-facing safeguards live in Magento's
            // InvoiceService, but a stale Invoice object could still slip through here.
            $remaining = (int)$orderItem->getQtyToInvoice();
            if ($remaining > 0 && $invoiceQty > $remaining) {
                throw new LocalizedException(__(
                    'Cannot invoice %1 of "%2": only %3 remain invoiceable on order #%4.',
                    $invoiceQty,
                    (string)$orderItem->getSku(),
                    $remaining,
                    (string)$this->order->getIncrementId()
                ));
            }

            $qliroOrderItem = $this->typeResolver->resolveQliroOrderItem(
                $this->orderSourceProvider->generateSourceItem($orderItem, $invoiceQty),
                $this->orderSourceProvider
            );

            if ($qliroOrderItem) {
                $qliroOrderItem['Quantity'] = (float)$invoiceQty;
                $shipmentOrderItems[] = $qliroOrderItem;
            }
        }

        if ($this->isFirstInvoice()) {
            $this->order->setFirstCaptureFlag(true);
        }

        foreach ($this->handlers as $handler) {
            if ($handler instanceof OrderItemHandlerInterface) {
                $shipmentOrderItems = $handler->handle($shipmentOrderItems, $this->order);
            }
        }

        $shipment = $this->qliroShipmentFactory->create();
        $shipment->setOrderItems($shipmentOrderItems);

        $this->payment = null;
        $this->order = null;
        $this->invoice = null;
        $this->orderSourceProvider->setOrder($this->order);
        return [$shipment];
    }

    /**
     * @return bool
     */
    private function isFirstInvoice(): bool
    {
        $invoiceCollection = $this->order->getInvoiceCollection();
        foreach ($invoiceCollection as $invoice) {
            if ($invoice->getId() == $this->invoice->getId()) {
                continue;
            }
            return false;
        }
        return true;
    }

    /**
     * Build a map of configurable-parent order_item_id → invoice qty, scanning the entire
     * invoice item list. The map is consumed by the main loop's child branch, decoupling
     * the parent-then-child ordering requirement that the previous single-pass code had.
     *
     * @param Item[] $invoiceItems
     * @return array<int|string, int>
     */
    private function buildConfigurableQuantityMap(array $invoiceItems): array
    {
        $map = [];
        foreach ($invoiceItems as $invoiceItem) {
            $orderItem = $this->order->getItemById($invoiceItem->getOrderItemId());
            if ($orderItem && $orderItem->getProductType() === 'configurable') {
                $map[$orderItem->getId()] = (int)$invoiceItem->getQty();
            }
        }
        return $map;
    }

    /**
     * Resolve an invoice item to its corresponding order item.
     *
     * @throws LocalizedException when the invoice item references an order item that
     *         does not exist on this order. The message names the SKU and the
     *         order_item_id so the unambiguous corrective action is obvious to the caller
     *         (admin user or API consumer).
     */
    private function resolveOrderItem(Item $invoiceItem): Order\Item
    {
        $orderItem = $this->order->getItemById($invoiceItem->getOrderItemId());
        if (!$orderItem) {
            throw new LocalizedException(__(
                'Invoice item with SKU "%1" references order_item_id %2 which is not on order #%3. '
                . 'Verify that the invoice was built from this order and that the order_item_id is correct.',
                (string)($invoiceItem->getSku() ?? '(no sku)'),
                (int)$invoiceItem->getOrderItemId(),
                (string)$this->order->getIncrementId()
            ));
        }
        return $orderItem;
    }
}
