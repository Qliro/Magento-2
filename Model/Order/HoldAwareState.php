<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Order;

use Magento\Sales\Model\Order;

/**
 * Sets the state of an order, a held order gets it as the state it returns to when released
 */
class HoldAwareState
{
    /**
     * @param Order $order
     * @param string $state
     * @param string|null $status The default status of the state when null
     */
    public static function apply(Order $order, string $state, ?string $status = null): void
    {
        $status ??= $order->getConfig()->getStateDefaultStatus($state);

        if ($order->getState() === Order::STATE_HOLDED) {
            $order->setHoldBeforeState($state);
            $order->setHoldBeforeStatus($status);

            return;
        }

        $order->setState($state);
        $order->setStatus($status);
    }
}
