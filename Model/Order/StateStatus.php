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
 * Decides the state and status of an order: a status is always one assigned to its state, a held order
 * keeps its hold, and the checkout does not move an order back once it is past it
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
        if (!in_array($this->currentState($order), self::CHECKOUT_STATES, true)) {
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

        $this->setKeepingHold($order, $state, $status);

        return true;
    }

    /**
     * Same as set(), but a held order keeps its hold and gets them as the state and status it returns to
     *
     * @param Order $order
     * @param string $state
     * @param string|null $status
     * @return void
     */
    public function setKeepingHold(Order $order, string $state, ?string $status = null): void
    {
        if ($order->getState() !== Order::STATE_HOLDED) {
            $this->set($order, $state, $status);

            return;
        }

        $order->setHoldBeforeState($state);
        $order->setHoldBeforeStatus($this->statusFor($order, $state, $status));
    }

    /**
     * The state of the order, or of a held order the state it returns to
     *
     * @param Order $order
     * @return string
     */
    public function currentState(Order $order): string
    {
        return (string)($order->getState() === Order::STATE_HOLDED ? $order->getHoldBeforeState() : $order->getState());
    }

    /**
     * Whether the status is assigned to the state
     *
     * @param Order $order
     * @param string $state
     * @param string $status
     * @return bool
     */
    public function isAssigned(Order $order, string $state, string $status): bool
    {
        return $status !== '' && in_array($status, $this->assigned($order, $state), true);
    }

    /**
     * Gives the order a status assigned to its state when its status is not, for example after Magento's
     * unhold() put back the status from before the hold, and says so in the order history
     *
     * @param Order $order
     * @return void
     */
    public function keepAssigned(Order $order): void
    {
        $state = (string)$order->getState();
        $status = (string)$order->getStatus();
        // A state without any status has nothing better to give
        if ($this->assigned($order, $state) === [] || $this->isAssigned($order, $state, $status)) {
            return;
        }

        $assigned = $this->statusFor($order, $state, $status);
        $order->setStatus($assigned);
        $order->addStatusHistoryComment(__(
            'The status %1 the order had before the hold is not assigned to state %2, so the order gets %3',
            $status,
            $state,
            $assigned
        ));
    }

    /**
     * @param Order $order
     * @param string $state
     * @param string|null $status
     * @return string
     */
    private function statusFor(Order $order, string $state, ?string $status): string
    {
        $registered = $this->assigned($order, $state);

        if ($status !== null && $this->isAssigned($order, $state, $status)) {
            return $status;
        }

        $default = (string)$order->getConfig()->getStateDefaultStatus($state);
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

    /**
     * @param Order $order
     * @param string $state
     * @return string[]
     */
    private function assigned(Order $order, string $state): array
    {
        return array_map('strval', (array)$order->getConfig()->getStateStatuses($state, false));
    }
}
