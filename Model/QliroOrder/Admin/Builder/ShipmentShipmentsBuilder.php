<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin\Builder;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Shipment;
use Magento\Sales\Model\Order\Shipment\Item;
use Qliro\QliroOne\Api\Admin\Builder\OrderItemHandlerInterface;
use Qliro\QliroOne\Api\Data\QliroShipmentInterface;
use Qliro\QliroOne\Model\Product\Type\OrderSourceProvider;
use Qliro\QliroOne\Model\Product\Type\TypePoolHandler;
use Qliro\QliroOne\Api\Data\QliroShipmentInterfaceFactory as QliroShipmentFactory;

/**
 * QliroOne Admin Order shipments builder class
 */
class ShipmentShipmentsBuilder
{
    private ?Order $order = null;
    private ?Shipment $shipment = null;
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
        array                                 $handlers = []
    ) {
        $this->handlers = $handlers;
    }

    /**
     * @param Shipment $shipment
     */
    public function setShipment(Shipment $shipment): void
    {
        $this->shipment = $shipment;

        /** @var Order $order */
        $this->order = $this->shipment->getOrder();
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
        $shipmentItems = iterator_to_array($this->shipment->getItemsCollection(), false);

        // Pass 1: pre-collect configurable PARENT shipment quantities. Doing this in a
        // separate pass makes the main loop order-independent — a child variant line that
        // appears BEFORE its configurable parent line in the input list still resolves
        // correctly, where the previous single-pass logic silently dropped it (`continue`).
        $configurableProducts = $this->buildConfigurableQuantityMap($shipmentItems);

        // Pass 2: build the Qliro order items.
        $shipmentOrderItems = [];
        /** @var Item $shipmentItem */
        foreach ($shipmentItems as $shipmentItem) {
            $orderItem   = $this->resolveOrderItem($shipmentItem);
            $shipmentQty = (int)$shipmentItem->getQty();

            // For configurable children, use the parent's (capped) shipment qty regardless of
            // where the parent appeared in the iteration.
            if ($orderItem->getParentItemId()) {
                if (!isset($configurableProducts[$orderItem->getParentItemId()])) {
                    // Child of a configurable that isn't part of this shipment — skip.
                    continue;
                }
                $shipmentQty = $configurableProducts[$orderItem->getParentItemId()];
            }

            if (!$shipmentQty) {
                continue;
            }

            // Defensive over-qty check (defence in depth — Magento's ShipmentService does
            // the user-facing validation, but a stale Shipment object should not let
            // unauthorised quantities slip into the Qliro request).
            $remaining = (int)$orderItem->getQtyToShip();
            if ($remaining > 0 && $shipmentQty > $remaining) {
                throw new LocalizedException(__(
                    'Cannot ship %1 of "%2": only %3 remain shippable on order #%4.',
                    $shipmentQty,
                    (string)$orderItem->getSku(),
                    $remaining,
                    (string)$this->order->getIncrementId()
                ));
            }

            $qliroOrderItem = $this->typeResolver->resolveQliroOrderItem(
                $this->orderSourceProvider->generateSourceItem($orderItem, $shipmentQty),
                $this->orderSourceProvider
            );

            if ($qliroOrderItem) {
                $qliroOrderItem['Quantity'] = (float)$shipmentQty;
                $shipmentOrderItems[] = $qliroOrderItem;
            }
        }

        if ($this->isFirstShipment()) {
            $this->order->setFirstCaptureFlag(true);
        }

        foreach ($this->handlers as $handler) {
            if ($handler instanceof OrderItemHandlerInterface) {
                $shipmentOrderItems = $handler->handle($shipmentOrderItems, $this->order);
            }
        }

        $shipment = $this->qliroShipmentFactory->create();
        $shipment->setOrderItems($shipmentOrderItems);

        $this->order = null;
        $this->shipment = null;
        $this->orderSourceProvider->setOrder($this->order);

        return [$shipment];
    }

    /**
     * @return bool
     */
    private function isFirstShipment(): bool
    {
        $invoiceCollection = $this->order->getInvoiceCollection();
        foreach ($invoiceCollection as $invoice) {
            return false;
        }
        $shipmentCollection = $this->order->getShipmentsCollection();
        foreach ($shipmentCollection as $shipment) {
            if ($shipment->getId() == $this->shipment->getId()) {
                continue;
            }
            return false;
        }
        return true;
    }

    /**
     * Build a map of configurable-parent order_item_id → shipment qty, scanning the entire
     * shipment item list. The qty is capped against "remaining unsubpoenaed" (qtyOrdered −
     * qtyInvoiced) to preserve the previous "invoice created before shipment" behaviour.
     *
     * The map is consumed by the main loop's child branch, decoupling the parent-then-child
     * ordering requirement that the previous single-pass code had.
     *
     * @param Item[] $shipmentItems
     * @return array<int|string, int>
     */
    private function buildConfigurableQuantityMap(array $shipmentItems): array
    {
        $map = [];
        foreach ($shipmentItems as $shipmentItem) {
            $orderItem = $this->order->getItemById($shipmentItem->getOrderItemId());
            if (!$orderItem || $orderItem->getProductType() !== 'configurable') {
                continue;
            }
            $shipmentQty = (int)$shipmentItem->getQty();
            // Preserve the old cap: if an invoice already exists, the parent qty for
            // shipping is capped at qtyOrdered − qtyInvoiced.
            if ($orderItem->getQtyInvoiced() > 0) {
                $remaining = (int)($orderItem->getQtyOrdered() - $orderItem->getQtyInvoiced());
                if ($remaining < $shipmentQty) {
                    $shipmentQty = $remaining;
                }
            }
            $map[$orderItem->getId()] = $shipmentQty;
        }
        return $map;
    }

    /**
     * Resolve a shipment item to its corresponding order item.
     *
     * @throws LocalizedException when the shipment item references an order item that
     *         does not exist on this order. The message names the SKU and the
     *         order_item_id so the unambiguous corrective action is obvious to the caller.
     */
    private function resolveOrderItem(Item $shipmentItem): Order\Item
    {
        $orderItem = $this->order->getItemById($shipmentItem->getOrderItemId());
        if (!$orderItem) {
            throw new LocalizedException(__(
                'Shipment item with SKU "%1" references order_item_id %2 which is not on order #%3. '
                . 'Verify that the shipment was built from this order and that the order_item_id is correct.',
                (string)($shipmentItem->getSku() ?? '(no sku)'),
                (int)$shipmentItem->getOrderItemId(),
                (string)$this->order->getIncrementId()
            ));
        }
        return $orderItem;
    }
}
