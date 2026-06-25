<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\OrderManagementStatus\Update\Handler;

use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order\Invoice;
use Qliro\QliroOne\Api\Admin\OrderManagementStatusUpdateHandlerInterface;
use Magento\Sales\Model\Order;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\Notification\QliroOrderManagementStatus;
use Qliro\QliroOne\Model\OrderManagementStatus;
use Qliro\QliroOne\Model\OrderManagementStatus\Update\CaptureTransactionUpdater;

class Shipment implements OrderManagementStatusUpdateHandlerInterface
{
    /**
     * @var ShipmentRepositoryInterface
     */
    private $shipmentRepository;

    /**
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var InvoiceRepositoryInterface
     */
    private $invoiceRepository;

    /**
     * @var Manager
     */
    private $logManager;

    /**
     * @var CaptureTransactionUpdater
     */
    private $captureTransactionUpdater;

    /**
     * Shipment constructor.
     * @param ShipmentRepositoryInterface $shipmentRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param InvoiceRepositoryInterface $invoiceRepository
     * @param Manager $logManager
     * @param CaptureTransactionUpdater $captureTransactionUpdater
     */
    public function __construct(
        ShipmentRepositoryInterface $shipmentRepository,
        OrderRepositoryInterface $orderRepository,
        InvoiceRepositoryInterface $invoiceRepository,
        Manager $logManager,
        CaptureTransactionUpdater $captureTransactionUpdater
    ) {
        $this->shipmentRepository = $shipmentRepository;
        $this->orderRepository = $orderRepository;
        $this->invoiceRepository = $invoiceRepository;
        $this->logManager = $logManager;
        $this->captureTransactionUpdater = $captureTransactionUpdater;
    }

    /**
     * @param QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @throws TerminalException
     */
    public function handleSuccess($qliroOrderManagementStatus, $omStatus)
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
                $payment->setTransactionId($qliroOrderManagementStatus->getPaymentTransactionId());
                $payment->setData(\Qliro\QliroOne\Model\Management::QLIRO_SKIP_ACTUAL_CAPTURE, 1);
                $invoice->register()->pay();
                $this->invoiceRepository->save($invoice);
            } else {
                throw new \Magento\Framework\Exception\LocalizedException(
                    __('Order does not allow to capture')
                );
            }

            $formattedPrice = $order->getBaseCurrency()->formatTxt(
                $qliroOrderManagementStatus->getAmount()
            );

            $order->addStatusHistoryComment(__('Capture of %1 confirmed successful', $formattedPrice));

            $this->orderRepository->save($order);

            $this->captureTransactionUpdater->update(
                $qliroOrderManagementStatus,
                $payment->getId(),
                $order->getId()
            );
        } catch (\Exception $exception) {
            $this->logManager->debug(
                $exception,
                [
                    'extra' => [
                        'qliro_order_id' => $qliroOrderManagementStatus->getOrderId(),
                        'shipment_id' => isset($shipment) ? $shipment->getId() : null,
                    ],
                ]
            );
            throw new TerminalException('Could not handle Shipment Success', $exception->getCode(), $exception);
        }
    }

    /**
     * @param QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @throws TerminalException
     */
    public function handleCancelled($qliroOrderManagementStatus, $omStatus)
    {
        $this->setCanceled($qliroOrderManagementStatus, $omStatus, 'Cancelled');
    }

    /**
     * @param QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @throws TerminalException
     */
    public function handleError($qliroOrderManagementStatus, $omStatus)
    {
        $this->setOnHold($qliroOrderManagementStatus, $omStatus, 'Error');
    }

    /**
     * @param QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     */
    public function handleInProcess($qliroOrderManagementStatus, $omStatus)
    {
        // Nothing to do
    }

    /**
     * @param QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @throws TerminalException
     */
    public function handleOnHold($qliroOrderManagementStatus, $omStatus)
    {
        $this->setPendingPayment($qliroOrderManagementStatus, $omStatus, 'OnHold');
    }

    /**
     * @param QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @throws TerminalException
     */
    public function handleUserInteraction($qliroOrderManagementStatus, $omStatus)
    {
        $this->setOnHold($qliroOrderManagementStatus, $omStatus, 'UserInteraction');
    }

    /**
     * @param QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     */
    public function handleCreated($qliroOrderManagementStatus, $omStatus)
    {
        // Nothing to do
    }

    /**
     * @param OrderManagementStatus $omStatus
     * @return Order\Shipment $shipment
     */
    private function getShipment($omStatus)
    {
        $shipment = $this->shipmentRepository->get($omStatus->getRecordId());

        return $shipment;
    }

    /**
     * @param QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @param string $contextMessage
     * @throws TerminalException
     */
    private function setOnHold($qliroOrderManagementStatus, $omStatus, $contextMessage)
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
                        'qliro_order_id' => $qliroOrderManagementStatus->getOrderId(),
                    ],
                ]
            );

            throw new TerminalException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * @param QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @param string $contextMessage
     * @throws TerminalException
     */
    private function setPendingPayment($qliroOrderManagementStatus, $omStatus, $contextMessage)
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
                        'qliro_order_id' => $qliroOrderManagementStatus->getOrderId(),
                    ],
                ]
            );

            throw new TerminalException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }

    /**
     * @param QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @param string $contextMessage
     * @throws TerminalException
     */
    private function setCanceled($qliroOrderManagementStatus, $omStatus, $contextMessage)
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
                        'qliro_order_id' => $qliroOrderManagementStatus->getOrderId(),
                    ],
                ]
            );

            throw new TerminalException($exception->getMessage(), $exception->getCode(), $exception);
        }
    }
}
