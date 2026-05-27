<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Management;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * Applies a Magento order state + status and persists the order.
 *
 * Extracted from PlaceOrder and PlaceRecurringOrder to avoid duplication (SRP).
 */
class OrderStateSetter
{
    public function __construct(
        private readonly Config $qliroConfig,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LogManager $logManager
    ) {
    }

    /**
     * Set the given state (and its appropriate status) on the order and save it.
     *
     * For STATE_NEW the configured QliroOne order status is used; for all other
     * states the default status for that state is used.
     *
     * @param Order  $order
     * @param string $state  One of the Order::STATE_* constants
     */
    public function apply(Order $order, string $state): void
    {
        $status = Order::STATE_NEW === $state
            ? $this->qliroConfig->getOrderStatus()
            : $order->getConfig()->getStateDefaultStatus($state);

        $order->setState($state);
        $order->setStatus($status);
        $this->orderRepository->save($order);
    }
}
