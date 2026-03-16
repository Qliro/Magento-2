<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Api\Admin;

use Magento\Payment\Model\InfoInterface;
use Magento\Sales\Model\Order\Shipment;
use Qliro\QliroOne\Api\Data\AdminOrderInterface;
use Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface;
use Qliro\QliroOne\Model\Exception\TerminalException;

/**
 * Post-placement, admin-side operations on Qliro orders.
 *
 * These methods operate on already-placed Magento orders and their
 * corresponding Qliro Order Management records. No active quote is involved.
 *
 * @api
 */
interface OrderServiceInterface
{
    /**
     * Cancel a Qliro order by Qliro order ID.
     *
     * @param int $qliroOrderId
     * @return AdminTransactionResponseInterface
     * @throws TerminalException
     */
    public function cancelQliroOrder(int $qliroOrderId): AdminTransactionResponseInterface;

    /**
     * Fetch a placed Qliro order via the Order Management API.
     *
     * @param int $qliroOrderId
     * @return AdminOrderInterface|null
     */
    public function getAdminQliroOrder(int $qliroOrderId): ?AdminOrderInterface;

    /**
     * Capture payment when an invoice is created.
     *
     * @param InfoInterface $payment
     * @param float $amount
     * @return void
     * @throws \Exception
     */
    public function captureByInvoice(InfoInterface $payment, float $amount): void;

    /**
     * Capture payment when a shipment is created.
     *
     * @param Shipment $shipment
     * @return void
     * @throws \Exception
     */
    public function captureByShipment(Shipment $shipment): void;

    /**
     * Refund payment when a credit memo is created.
     *
     * @param InfoInterface $payment
     * @param float $amount
     * @return void
     * @throws \Exception
     */
    public function refundByInvoice(InfoInterface $payment, float $amount): void;

    /**
     * Handle an Order Management Status transaction push from Qliro.
     *
     * @param array $qliroOrderManagementStatus  Raw decoded JSON from the Qliro push
     * @return array  Response payload
     * @throws \Exception
     */
    public function handleTransactionStatus(array $qliroOrderManagementStatus): array;

    /**
     * Handle a Merchant Notification push from Qliro.
     *
     * @param array $container  Raw decoded JSON from the Qliro push
     * @return array  Response payload
     */
    public function merchantNotification(array $container): array;

    /**
     * Store a saved credit card ID for a recurring order.
     *
     * @param array $notification  Raw decoded JSON from the Qliro push
     * @return array  Response payload
     */
    public function updateOrderSavedCreditCard(array $notification): array;
}
