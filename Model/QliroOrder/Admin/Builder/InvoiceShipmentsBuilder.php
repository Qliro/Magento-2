<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin\Builder;

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

        $shipmentOrderItems = [];

        /*
         * Contains the order item id of each valid configurable about to be invoiced in this format:
         * $configurableProducts['order item id of configurable'] = quantity about to be captured
         */
        $configurableProducts = [];

        /** @var Item $invoiceItem */
        foreach ($this->invoice->getAllItems() as $invoiceItem) {
            /** @var Order\Item $orderItem */
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
}
