<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\OrderManagementStatus\Update\Handler;

use Magento\Sales\Api\InvoiceRepositoryInterface as InvoiceRepository;
use Magento\Sales\Api\OrderRepositoryInterface as OrderRepository;
use Magento\Sales\Api\ShipmentRepositoryInterface as ShipmentRepository;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Shipment\Item;
use Qliro\QliroOne\Api\Admin\OrderManagementStatusUpdateHandlerInterface;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\OrderManagementStatus;

class Shipment implements OrderManagementStatusUpdateHandlerInterface
{
    /**
     * Class constructor
     *
     * @param ShipmentRepository            $shipmentRepository
     * @param OrderRepository               $orderRepository
     * @param InvoiceRepository             $invoiceRepository
     * @param Manager                       $logManager
     */
    public function __construct(
        private readonly ShipmentRepository $shipmentRepository,
        private readonly OrderRepository    $orderRepository,
        private readonly InvoiceRepository  $invoiceRepository,
        private readonly Manager            $logManager
    ) {
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @throws TerminalException
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

            /** @var Item $shipmentItem */
            foreach ($shipmentItems as $shipmentItem) {
                $qty = (int)$shipmentItem->getQty();

                /** @var Order\Item $item */
                $item = $order->getItemById($shipmentItem->getOrderItemId());

                if ($item->getQtyInvoiced() > 0) {
                    $remaining = $item->getQtyOrdered() - $item->getQtyInvoiced();
                    if ($remaining < $qty) {
                        $qty = $remaining;
                    }
                }

                $invoiceItems[$shipmentItem->getOrderItemId()] = $qty;
            }

            foreach ($order->getAllItems() as $orderItem) {
                if ($orderItem->getParentItemId()) {
                    continue;
                }

                $isNonShippable = (bool)$orderItem->getIsVirtual()
                    || in_array($orderItem->getProductType(), ['virtual', 'downloadable'], true);
                if (!$isNonShippable) {
                    continue;
                }

                $remaining = (int)$orderItem->getQtyToInvoice();
                if ($remaining > 0) {
                    $invoiceItems[$orderItem->getId()] = $remaining;
                }
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

            $order->addCommentToStatusHistory(__('Capture of %1 confirmed successful', $formattedPrice));

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
     * @throws TerminalException
     */
    public function handleCancelled(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        $this->setCanceled($qliroOrderManagementStatus, $omStatus, 'Cancelled');
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @throws TerminalException
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
     * @throws TerminalException
     */
    public function handleOnHold(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void
    {
        $this->setPendingPayment($qliroOrderManagementStatus, $omStatus, 'OnHold');
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @throws TerminalException
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
     * @return Order\Shipment
     */
    private function getShipment(OrderManagementStatus $omStatus): Order\Shipment
    {
        return $this->shipmentRepository->get($omStatus->getRecordId());
    }

    /**
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @param string $contextMessage
     * @throws TerminalException
     */
    private function setOnHold(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus, string $contextMessage): void
    {
        try {
            $shipment = $this->getShipment($omStatus);
            $order = $shipment->getOrder();
            $order->hold();

            $order->addCommentToStatusHistory(
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
     * @throws TerminalException
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
            $order->addCommentToStatusHistory(
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
     * @throws TerminalException
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
            $order->addCommentToStatusHistory(
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
