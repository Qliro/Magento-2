<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin\Builder;

use Qliro\QliroOne\Api\Admin\Builder\OrderItemHandlerInterface;
use Qliro\QliroOne\Api\Data\QliroShipmentInterface;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\AppliedRulesHandler;
use Qliro\QliroOne\Model\Product\Type\OrderSourceProvider;
use Qliro\QliroOne\Model\Product\Type\TypePoolHandler;
use Qliro\QliroOne\Model\QliroOrder\LineQuantity;
use Qliro\QliroOne\Model\QliroOrder\Admin\BundleChildren;
use Qliro\QliroOne\Model\QliroOrder\LineReference;
use Qliro\QliroOne\Api\Data\QliroShipmentInterfaceFactory;

/**
 * QliroOne Admin Order shipments builder class
 */
class ShipmentShipmentsBuilder
{
    /**
     * @var \Magento\Sales\Model\Order
     */
    private $order;

    /**
     * @var \Magento\Sales\Model\Order\Shipment
     */
    private $shipment;

    /**
     * @var \Qliro\QliroOne\Model\Product\Type\TypePoolHandler
     */
    private $typeResolver;

    /**
     * @var \Qliro\QliroOne\Api\Data\QliroShipmentInterfaceFactory
     */
    private $qliroShipmentFactory;

    /**
     * @var \Qliro\QliroOne\Api\Admin\Builder\OrderItemHandlerInterface[]
     */
    private $handlers = [];

    /**
     * @var OrderSourceProvider
     */
    private $orderSourceProvider;

    /**
     * @var LineReference
     */
    private $lineReference;

    /**
     * @var LineQuantity
     */
    private $lineQuantity;

    /**
     * @var BundleChildren
     */
    private $bundleChildren;

    /**
     * Inject dependencies
     *
     * @param \Qliro\QliroOne\Model\Product\Type\TypePoolHandler $typeResolver
     * @param \Qliro\QliroOne\Api\Data\QliroShipmentInterfaceFactory $qliroShipmentFactory
     * @param OrderSourceProvider $orderSourceProvider
     * @param \Qliro\QliroOne\Api\Admin\Builder\OrderItemHandlerInterface[] $handlers
     * @param LineReference|null $lineReference
     * @param LineQuantity|null $lineQuantity
     * @param BundleChildren|null $bundleChildren
     */
    public function __construct(
        TypePoolHandler $typeResolver,
        QliroShipmentInterfaceFactory $qliroShipmentFactory,
        OrderSourceProvider $orderSourceProvider,
        $handlers = [],
        ?LineReference $lineReference = null,
        ?LineQuantity $lineQuantity = null,
        ?BundleChildren $bundleChildren = null
    ) {
        $this->typeResolver = $typeResolver;
        $this->qliroShipmentFactory = $qliroShipmentFactory;
        $this->orderSourceProvider = $orderSourceProvider;
        $this->handlers = $handlers;
        // Optional so a store constructing this builder with the old signature keeps working
        $this->lineReference = $lineReference ?? new LineReference();
        $this->lineQuantity = $lineQuantity ?? new LineQuantity();
        $this->bundleChildren = $bundleChildren ?? new BundleChildren();
    }

    /**
     * @param \Magento\Sales\Model\Order\Shipment $shipment
     */
    public function setShipment($shipment)
    {
        $this->shipment = $shipment;

        /** @var \Magento\Sales\Model\Order $order */
        $this->order = $this->shipment->getOrder();
    }

    /**
     * Create an array of containers
     *
     * @return \Qliro\QliroOne\Api\Data\QliroShipmentInterface[];
     */
    public function create()
    {
        if (empty($this->order)) {
            throw new \LogicException('Order entity is not set.');
        }

        $shipmentOrderItems = [];

        /*
         * Contains the order item id of each valid configurable about to be shipped in this format:
         * $configurableProducts['order item id of configurable'] = quantity about to be captured
         */
        $configurableProducts = [];
        $bundleLines = [];

        /** @var \Magento\Sales\Model\Order\Shipment\Item $shipmentItem */
        foreach ($this->shipment->getItemsCollection() as $shipmentItem) {
            /** @var \Magento\Sales\Model\Order\Item $orderItem */
            $orderItem = $this->order->getItemById($shipmentItem->getOrderItemId());
            $shipmentQty = (float)$shipmentItem->getQty();

            if ($orderItem->getProductType() == 'configurable') {
                /**
                 * This calculates how many items to ship, in case invoice was created Before shipment
                 */
                if ($orderItem->getQtyInvoiced() > 0) {
                    $remaining = $orderItem->getQtyOrdered() - $orderItem->getQtyInvoiced();
                    if ($remaining < $shipmentQty) {
                        $shipmentQty = $remaining;
                    }
                }
                $configurableProducts[$orderItem->getId()] = $shipmentQty;
            }

            $isBundleChild = $this->bundleChildren->isPricedChild($orderItem, $this->order);

            if ($orderItem->getParentItemId()) {
                if (isset($configurableProducts[$orderItem->getParentItemId()])) {
                    $shipmentQty = $configurableProducts[$orderItem->getParentItemId()];
                } elseif (!$isBundleChild || !$orderItem->isShipSeparately()) {
                    // Shipped together, the children go out with the bundle row instead
                    continue;
                }
            }

            if (!$shipmentQty) {
                continue;
            }

            $isBundle = $this->bundleChildren->isPricedByChildren($orderItem);
            $lines = [[$orderItem, $shipmentQty]];

            if ($isBundle) {
                array_push($lines, ...$this->bundleChildren->shippedTogether($orderItem, $shipmentQty));
            }

            foreach ($lines as [$lineItem, $lineQty]) {
                $qliroOrderItem = $this->typeResolver->resolveQliroOrderItem(
                    $this->orderSourceProvider->generateSourceItem($lineItem, $lineQty),
                    $this->orderSourceProvider
                );

                if ($qliroOrderItem) {
                    // Asked here rather than at the top of the loop, because this is the quantity that
                    // reaches Qliro: a line the loop skips is never sent, and the quantity left to
                    // invoice, which replaces the shipped one above, is what a legacy partial invoice
                    // can make fractional
                    $qliroOrderItem->setQuantity(
                        $this->lineQuantity->settlementQuantity($lineQty, (string)$lineItem->getSku())
                    );
                    $shipmentOrderItems[] = $qliroOrderItem;

                    if ($isBundleChild || $isBundle) {
                        $bundleLines[] = $qliroOrderItem;
                    }
                }
            }
        }

        $shipmentOrderItems = $this->bundleChildren->withinReservation($shipmentOrderItems, $bundleLines, $this->order);

        if ($this->isFirstShipment()) {
            $this->order->setFirstCaptureFlag(true);
        }

        $this->order->setData(AppliedRulesHandler::FINAL_CAPTURE_FLAG, $this->isFinalShipment());

        foreach ($this->handlers as $handler) {
            if ($handler instanceof OrderItemHandlerInterface) {
                $shipmentOrderItems = $handler->handle($shipmentOrderItems, $this->order);
            }
        }

        $shipmentOrderItems = $this->lineReference->alignWithReservation($shipmentOrderItems, $this->order);

        $this->orderSourceProvider->setOrder(null);
        $this->order = null;
        $this->shipment = null;

        if (empty($shipmentOrderItems)) {
            return [];
        }

        $shipment = $this->qliroShipmentFactory->create();
        $shipment->setOrderItems($shipmentOrderItems);

        return [$shipment];
    }

    /**
     * @return bool
     */
    private function isFirstShipment()
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
     * Whether this shipment leaves nothing of the order to capture, Magento counts it in already
     *
     * @return bool
     */
    private function isFinalShipment()
    {
        foreach ($this->order->getAllItems() ?: [] as $item) {
            // A virtual item is never shipped, so it never takes part in a capture on shipment
            if (!$item->isDummy(true) && !$item->getIsVirtual() && $item->getQtyToShip() > 0) {
                return false;
            }
        }

        return true;
    }
}
