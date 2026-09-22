<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder;

use Magento\Sales\Model\Order;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * The line reference format the Qliro reservation of an order holds
 *
 * Qliro refuses a capture whose line references disagree with the reservation, and refuses it
 * terminally, so a capture has to repeat the format the order was reserved with. An order the
 * module placed from 1.7.42 on is stamped with that format when it is placed. An older one is
 * not, and it can hold either: 1.7.0 to 1.7.41 reserved with the bare sku, and every version
 * before 1.7.0 reserved with the cart item id in front of it, which is the format 1.7.42 went
 * back to. Nothing on the order records which version placed it, so the reservation itself is
 * asked, once, and the answer is stamped on the payment like a newer order's own.
 */
class ReservationFormat
{
    /**
     * @var OrderManagementInterface
     */
    private $orderManagementApi;

    /**
     * @var LineReference
     */
    private $lineReference;

    /**
     * @var LogManager
     */
    private $logManager;

    /**
     * Inject dependencies
     *
     * @param OrderManagementInterface $orderManagementApi
     * @param LineReference $lineReference
     * @param LogManager $logManager
     */
    public function __construct(
        OrderManagementInterface $orderManagementApi,
        LineReference $lineReference,
        LogManager $logManager
    ) {
        $this->orderManagementApi = $orderManagementApi;
        $this->lineReference = $lineReference;
        $this->logManager = $logManager;
    }

    /**
     * Stamp the order with the format its reservation holds, unless it carries the stamp already
     *
     * @param Order $order
     * @param int $qliroOrderId
     * @return bool whether a stamp was written, so a caller can rebuild what it built unstamped
     */
    public function stamp(Order $order, $qliroOrderId)
    {
        $payment = $order->getPayment();

        if ($payment === null) {
            return false;
        }

        $stamp = $payment->getAdditionalInformation(
            Config::QLIROONE_ADDITIONAL_INFO_LINE_REFERENCE_CARRIES_ITEM_ID
        );

        if ($stamp !== null) {
            return false;
        }

        $carriesItemId = $this->readFromReservation($order, $qliroOrderId);

        // The reservation answered neither format, so the order is left unstamped: it keeps being
        // read the way an unstamped order is read today and the next capture asks again, which is
        // better than stamping it on a guess Qliro would refuse terminally
        if ($carriesItemId === null) {
            return false;
        }

        $payment->setAdditionalInformation(
            Config::QLIROONE_ADDITIONAL_INFO_LINE_REFERENCE_CARRIES_ITEM_ID,
            $carriesItemId
        );

        return true;
    }

    /**
     * Whether the reservation carries the cart item id, or null when it answers neither format
     *
     * @param Order $order
     * @param int $qliroOrderId
     * @return bool|null
     */
    private function readFromReservation(Order $order, $qliroOrderId)
    {
        try {
            // Nobody is waiting for this one, it runs on the way to a capture
            $qliroOrder = $this->orderManagementApi->getOrder(
                $qliroOrderId,
                $order->getStoreId(),
                Config::API_PROFILE_BACKGROUND
            );
        } catch (\Throwable $exception) {
            // Throwable, not Exception: a line Qliro sends with no MerchantReference makes the
            // mapper pass null to a string typed setter, and that TypeError has to leave the
            // format unknown like any other unreadable answer
            $this->logManager->warning(
                'The Qliro order could not be read, the format of its line references is unknown',
                [
                    'extra' => [
                        'qliro_order_id' => $qliroOrderId,
                        'order_increment_id' => $order->getIncrementId(),
                        'error' => $exception->getMessage(),
                    ],
                ]
            );

            return null;
        }

        $reserved = [];

        // An order Qliro answers without any lines leaves this empty rather than raising, the
        // format then stays unknown like any other answer that matches nothing
        foreach ($qliroOrder->getOrderItemActions() ?: [] as $line) {
            $reference = $line->getMerchantReference();

            // A line the response carried no reference for stands for nothing, and would otherwise
            // answer for an order item that has no sku
            if ($reference !== '') {
                $reserved[$reference] = true;
            }
        }

        $carriesItemId = false;
        $bareSku = false;

        foreach ($order->getAllItems() as $item) {
            $sku = (string)$item->getSku();

            if ($sku === '') {
                continue;
            }

            $itemId = $item->getQuoteItemId();

            // Without a cart item id behind it the item builds the same reference in both formats,
            // so it may only answer for the bare sku
            if ($itemId !== null && $itemId !== ''
                && isset($reserved[$this->lineReference->forItem($itemId, $sku)])
            ) {
                $carriesItemId = true;
            }

            if (isset($reserved[$sku])) {
                $bareSku = true;
            }
        }

        // A reservation the module built holds one format throughout, so both answers at once
        // means its lines are not the ones this order was built from and neither is trustworthy
        if ($carriesItemId === $bareSku) {
            $this->logManager->warning(
                $carriesItemId
                    ? 'The Qliro reservation holds both line reference formats, neither is assumed'
                    : 'No line of the Qliro reservation matches an item of the order',
                [
                    'extra' => [
                        'qliro_order_id' => $qliroOrderId,
                        'order_increment_id' => $order->getIncrementId(),
                        'reserved_references' => array_keys($reserved),
                    ],
                ]
            );

            return null;
        }

        return $carriesItemId;
    }
}
