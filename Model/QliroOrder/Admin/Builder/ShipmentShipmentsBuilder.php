<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin\Builder;

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

        $shipmentOrderItems = [];

        /*
         * Contains the order item id of each valid configurable about to be shipped in this format:
         * $configurableProducts['order item id of configurable'] = quantity about to be captured
         */
        $configurableProducts = [];

        /** @var Item $shipmentItem */
        foreach ($this->shipment->getItemsCollection() as $shipmentItem) {
            /** @var Order\Item $orderItem */
            $orderItem = $this->order->getItemById($shipmentItem->getOrderItemId());
            $shipmentQty = (int)$shipmentItem->getQty();

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

            if ($orderItem->getParentItemId()) {
                if (!isset($configurableProducts[$orderItem->getParentItemId()])) {
                    continue;
                }
                $shipmentQty = $configurableProducts[$orderItem->getParentItemId()];
            }

            if (!$shipmentQty) {
                continue;
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
}
