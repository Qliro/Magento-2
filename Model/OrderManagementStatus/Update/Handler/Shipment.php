<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\OrderManagementStatus\Update\Handler;

use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Qliro\QliroOne\Api\Admin\OrderManagementStatusUpdateHandlerInterface;
use Magento\Sales\Model\Order;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\OrderManagementStatus;

class Shipment implements OrderManagementStatusUpdateHandlerInterface
{
    /**
     * Class constructor
     *
     * @param \Magento\Sales\Api\ShipmentRepositoryInterface $shipmentRepository
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Magento\Sales\Api\InvoiceRepositoryInterface $invoiceRepository
     * @param \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $transactionBuilder
     * @param \Qliro\QliroOne\Model\Logger\Manager $logManager
     */
    public function __construct(
        private readonly ShipmentRepositoryInterface $shipmentRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly InvoiceRepositoryInterface $invoiceRepository,
        private readonly BuilderInterface $transactionBuilder,
        private readonly Manager $logManager
    ) {
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     */
    public function handleSuccess(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        try {
            $shipment = $this->getShipment($omStatus);
            $order = $shipment->getOrder();
            $payment = $order->getPayment();

            /*
             * Update Order
             */
            if ($order->getState() == Order::STATE_HOLDED) {
                $order->unhold();
                $this->orderRepository->save($order);
            }

            /*
             * Create Invoice
             */
            $invoiceItems = [];
            $shipmentItems = $shipment->getAllItems();

            /** @var \Magento\Sales\Model\Order\Shipment\Item $shipmentItem */
            foreach ($shipmentItems as $shipmentItem) {
                $qty = (int)$shipmentItem->getQty();

                /** @var \Magento\Sales\Model\Order\Item $item */
                $item = $order->getItemById($shipmentItem->getOrderItemId());

                /*
                 * This is the same test for invoice made earlier, as seen in:
                 * \Qliro\QliroOne\Model\QliroOrder\Admin\Builder\ShipmentOrderItemsBuilder::create
                 */
                if ($item->getQtyInvoiced() > 0) {
                    $remaining = $item->getQtyOrdered() - $item->getQtyInvoiced();
                    if ($remaining < $qty) {
                        $qty = $remaining;
                    }
                }

                $invoiceItems[$shipmentItem->getOrderItemId()] = $qty;
            }

            /*
             * Capture online is selected, to make use of all the functions that it runs (payment
             * transactions etc). "qliro_skip_actual_capture" is set to avoid doing the capture
             * inside, since it was already done by the shipment
             */
            if ($order->canInvoice()) {
                $invoice = $order->prepareInvoice($invoiceItems);
                $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
                $payment->setTransactionId($qliroOrderManagementStatus['PaymentTransactionId'] ?? null);
                $payment->setData(\Qliro\QliroOne\Model\Management\Payment::QLIRO_SKIP_ACTUAL_CAPTURE, 1);
                $invoice->register()->pay();
                $this->invoiceRepository->save($invoice);
            } else {
                $payment->setTransactionId($qliroOrderManagementStatus['PaymentTransactionId'] ?? null);
                $payment->setLastTransId($qliroOrderManagementStatus['PaymentTransactionId'] ?? null);
                foreach ($order->getInvoiceCollection() as $existingInvoice) {
                    if ((int)$existingInvoice->getState() === Invoice::STATE_OPEN) {
                        $existingInvoice->setTransactionId($qliroOrderManagementStatus['PaymentTransactionId'] ?? null);
                        $existingInvoice->pay();
                        $this->invoiceRepository->save($existingInvoice);
                        break;
                    }
                }
            }

            $formattedPrice = $order->getBaseCurrency()->formatTxt(
                $qliroOrderManagementStatus['Amount'] ?? null
            );

            $order->addStatusHistoryComment(__('Capture of %1 confirmed successful', $formattedPrice));

            $this->orderRepository->save($order);
        } catch (\Exception $exception) {
            $this->logManager->debug(
                $exception,
                [
                    'extra' => [
                        'qliro_order_id' => $qliroOrderManagementStatus['OrderId'] ?? null,
                        'shipment_id' => isset($shipment) ? $shipment->getId() : null,
                    ],
                ]
            );
            throw new TerminalException('Could not handle Shipment Success', $exception->getCode(), $exception);
        }
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     */
    public function handleCancelled(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        $this->setCanceled($qliroOrderManagementStatus, $omStatus, 'Cancelled');
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     */
    public function handleError(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        $this->setOnHold($qliroOrderManagementStatus, $omStatus, 'Error');
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     */
    public function handleInProcess(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        // Nothing to do
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     */
    public function handleOnHold(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        $this->setPendingPayment($qliroOrderManagementStatus, $omStatus, 'OnHold');
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     */
    public function handleUserInteraction(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        $this->setOnHold($qliroOrderManagementStatus, $omStatus, 'UserInteraction');
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     */
    public function handleCreated(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        // Nothing to do
    }

    /**
     * @param OrderManagementStatus $omStatus
     * @return \Magento\Sales\Model\Order\Shipment
     */
    private function getShipment(OrderManagementStatus $omStatus): \Magento\Sales\Model\Order\Shipment
    {
        return $this->shipmentRepository->get($omStatus->getRecordId());
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @param string $contextMessage
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     */
    private function setOnHold(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus, string $contextMessage): void
    {
        try {
            $shipment = $this->getShipment($omStatus);
            $order = $shipment->getOrder();
            $order->hold();

            $order->addStatusHistoryComment(
                __('Order set on hold because Qliro One reported an error with the capture: %1', $contextMessage)
            );

            $this->orderRepository->save($order);
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'qliro_order_id' => $qliroOrderManagementStatus['OrderId'] ?? null,
                    ],
                ]
            );

            throw new TerminalException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @param string $contextMessage
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     */
    private function setPendingPayment(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus, string $contextMessage): void
    {
        try {
            $shipment = $this->getShipment($omStatus);
            $order = $shipment->getOrder();
            if ($order->getState() == Order::STATE_PENDING_PAYMENT) {
                return;
            }
            $order->setState(Order::STATE_PENDING_PAYMENT);
            $order->addStatusHistoryComment(
                __('Order set to pending payment because Qliro One returned the capture with status: %1', $contextMessage)
            );
            $this->orderRepository->save($order);
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'qliro_order_id' => $qliroOrderManagementStatus['OrderId'] ?? null,
                    ],
                ]
            );

            throw new TerminalException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @param string $contextMessage
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     */
    private function setCanceled(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus, string $contextMessage): void
    {
        try {
            $shipment = $this->getShipment($omStatus);
            $order = $shipment->getOrder();
            if ($order->isCanceled()) {
                return;
            }
            $order->cancel();
            $order->addStatusHistoryComment(
                __('Order canceled because Qliro One returned the capture with status: %1', $contextMessage)
            );
            $this->orderRepository->save($order);
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'qliro_order_id' => $qliroOrderManagementStatus['OrderId'] ?? null,
                    ],
                ]
            );

            throw new TerminalException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }
}
