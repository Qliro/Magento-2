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
class InvoiceShipmentsBuilder
{
    /**
     * @var \Magento\Sales\Model\Order\Payment
     */
    private $payment;

    /**
     * @var \Magento\Sales\Model\Order
     */
    private $order;

    /**
     * @var \Magento\Sales\Model\Order\Invoice
     */
    private $invoice;

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
     * @param \Magento\Sales\Model\Order\Payment $payment
     */
    public function setPayment($payment)
    {
        $this->payment = $payment;

        /** @var \Magento\Sales\Model\Order $order */
        $this->order = $this->payment->getOrder();

        /** @var  \Magento\Sales\Model\Order\Invoice $invoice */
        $this->invoice = $this->payment->getInvoice();
    }

    /**
     * Create an array of containers
     *
     * @return \Qliro\QliroOne\Api\Data\QliroShipmentInterface[]
     */
    public function create()
    {
        if (empty($this->order)) {
            throw new \LogicException('Order entity is not set.');
        }

        $shipmentOrderItems = [];

        /*
         * Contains the order item id of each valid configurable about to be invoiced in this format:
         * $configurableProducts['order item id of configurable'] = quantity about to be captured
         */
        $configurableProducts = [];

        /** @var \Magento\Sales\Model\Order\Invoice\Item $invoiceItem */
        foreach ($this->invoice->getAllItems() as $invoiceItem) {
            /** @var \Magento\Sales\Model\Order\Item $orderItem */
            $orderItem = $this->order->getItemById($invoiceItem->getOrderItemId());
            $invoiceQty = (int)$invoiceItem->getQty();

            if ($orderItem->getProductType() == 'configurable') {
                $configurableProducts[$orderItem->getId()] = $invoiceQty;
            }

            if ($orderItem->getParentItemId()) {
                if (!isset($configurableProducts[$orderItem->getParentItemId()])) {
                    continue;
                }
                $invoiceQty = $configurableProducts[$orderItem->getParentItemId()];
            }

            if (!$invoiceQty) {
                continue;
            }

            $qliroOrderItem = $this->typeResolver->resolveQliroOrderItem(
                $this->orderSourceProvider->generateSourceItem($orderItem, $invoiceQty),
                $this->orderSourceProvider
            );

            if ($qliroOrderItem) {
                $qliroOrderItem->setQuantity($invoiceQty);
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
    private function isFirstInvoice()
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
}

