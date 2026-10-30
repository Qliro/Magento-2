<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Order;

use Magento\Sales\Model\Order;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * Puts an order in a state together with a status registered for that state
 */
class StateStatus
{
    /**
     * States a checkout status may still move an order out of
     */
    private const CHECKOUT_STATES = [Order::STATE_NEW, Order::STATE_PENDING_PAYMENT, Order::STATE_PAYMENT_REVIEW];

    /**
     * @var LogManager
     */
    private LogManager $logManager;

    /**
     * @param LogManager $logManager
     */
    public function __construct(LogManager $logManager)
    {
        $this->logManager = $logManager;
    }

    /**
     * Set the state, and the status when it is registered for it, else the state's default
     *
     * @param Order $order
     * @param string $state
     * @param string|null $status
     * @return void
     */
    public function set(Order $order, string $state, ?string $status = null): void
    {
        $order->setState($state);
        $order->setStatus($this->statusFor($order, $state, $status));
    }

    /**
     * Same as set(), unless the order is past checkout (invoiced, shipped, closed), which it leaves alone.
     * A held order keeps its hold and gets them as the state and status it returns to
     *
     * @param Order $order
     * @param string $state
     * @param string|null $status
     * @return bool whether the order was changed
     */
    public function setFromCheckout(Order $order, string $state, ?string $status = null): bool
    {
        $held = $order->getState() === Order::STATE_HOLDED;
        $current = $held ? $order->getHoldBeforeState() : $order->getState();

        if (!in_array($current, self::CHECKOUT_STATES, true)) {
            $this->logManager->warning(
                sprintf(
                    'Order is already %s / %s, it is not moved back to %s',
                    $order->getState(),
                    $order->getStatus(),
                    $state
                ),
                ['extra' => ['order_id' => $order->getId()]]
            );

            return false;
        }

        if ($held) {
            $order->setHoldBeforeState($state);
            $order->setHoldBeforeStatus($this->statusFor($order, $state, $status));
        } else {
            $this->set($order, $state, $status);
        }

        return true;
    }

    /**
     * @param Order $order
     * @param string $state
     * @param string|null $status
     * @return string
     */
    private function statusFor(Order $order, string $state, ?string $status): string
    {
        $config = $order->getConfig();
        $registered = $config->getStateStatuses($state, false);

        if ($status !== null && $status !== '' && in_array($status, $registered, true)) {
            return $status;
        }

        $default = (string)$config->getStateDefaultStatus($state);
        $fallback = in_array($default, $registered, true) ? $default : (string)($registered[0] ?? $state);

        $asked = $status !== null && $status !== '';
        if ($asked || $fallback !== $default) {
            $this->logManager->warning(
                $asked
                    ? sprintf('Status %s is not assigned to state %s, the order gets %s', $status, $state, $fallback)
                    : sprintf('State %s has no default status, the order gets %s', $state, $fallback),
                ['extra' => ['order_id' => $order->getId()]]
            );
        }

        return $fallback;
    }
}
