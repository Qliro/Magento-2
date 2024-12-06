<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Admin\Builder;

use Qliro\QliroOne\Api\Admin\Builder\OrderItemHandlerInterface;
use Qliro\QliroOne\Api\Data\QliroShipmentInterface;
use Qliro\QliroOne\Model\Product\Type\OrderSourceProvider;
use Qliro\QliroOne\Model\Product\Type\TypePoolHandler;
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
     * Inject dependencies
     *
     * @param \Qliro\QliroOne\Model\Product\Type\TypePoolHandler $typeResolver
     * @param \Qliro\QliroOne\Api\Data\QliroShipmentInterfaceFactory $qliroShipmentFactory
     * @param OrderSourceProvider $orderSourceProvider
     * @param \Qliro\QliroOne\Api\Admin\Builder\OrderItemHandlerInterface[] $handlers
     */
    public function __construct(
        TypePoolHandler $typeResolver,
        QliroShipmentInterfaceFactory $qliroShipmentFactory,
        OrderSourceProvider $orderSourceProvider,
        $handlers = []
    ) {
        $this->typeResolver = $typeResolver;
        $this->qliroShipmentFactory = $qliroShipmentFactory;
        $this->orderSourceProvider = $orderSourceProvider;
        $this->handlers = $handlers;
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

        /** @var \Magento\Sales\Model\Order\Shipment\Item $shipmentItem */
        foreach ($this->shipment->getItemsCollection() as $shipmentItem) {
            /** @var \Magento\Sales\Model\Order\Item $orderItem */
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
                $qliroOrderItem->setQuantity($shipmentQty);
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
}
