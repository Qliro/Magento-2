<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\OrderManagementStatus\Update;

use Magento\Sales\Model\Order;

/**
 * Which failed captures keep the order on hold. Only the success of a failed capture (Qliro heals it)
 * counts towards releasing the hold, the hold is released once none is left, and only a hold the
 * module placed: a hold the merchant placed is theirs. A hold or release by anyone else ends the
 * tracking (Plugin\Magento\Sales\Model\OrderHoldPlugin), and so does any success while the order is
 * not held.
 */
class CaptureHold
{
    public const KEY_FAILED = 'qliro_capture_failed_transactions';
    public const KEY_SUCCEEDED = 'qliro_capture_succeeded_transactions';
    public const KEY_HELD = 'qliro_capture_hold';

    /** Set on the order while this class holds or releases it */
    public const OWN_ACTION = 'qliro_capture_hold_action';

    /**
     * Holds the order for a failed capture, or keeps the hold it is on. Where Magento cannot hold the
     * order (complete, closed, canceled) the order history says which capture failed.
     *
     * @param Order $order
     * @param string|int $transactionId
     * @param string $contextMessage
     * @return bool false when that capture already succeeded: a late or repeated Error
     */
    public function hold(Order $order, $transactionId, string $contextMessage): bool
    {
        $payment = $order->getPayment();
        $transactionId = (string)$transactionId;
        if (in_array($transactionId, $this->list($order, self::KEY_SUCCEEDED), true)) {
            return false;
        }

        $failed = $this->list($order, self::KEY_FAILED);
        $failed[] = $transactionId;
        $payment->setAdditionalInformation(self::KEY_FAILED, array_values(array_unique($failed)));

        if ($this->isHeld($order)) {
            $order->addStatusHistoryComment(__(
                'Qliro One reported %1 for capture %2, the order stays on hold',
                $contextMessage,
                $transactionId
            ));
        } elseif ($order->canHold()) {
            $this->own($order, static fn () => $order->hold());
            $payment->setAdditionalInformation(self::KEY_HELD, true);
            $order->addStatusHistoryComment(__(
                'Order set on hold because Qliro One reported %1 for capture %2',
                $contextMessage,
                $transactionId
            ));
        } else {
            $order->addStatusHistoryComment(__(
                'Qliro One reported %1 for capture %2, the order cannot be held in state %3',
                $contextMessage,
                $transactionId,
                $order->getState()
            ));
        }

        return true;
    }

    /**
     * A capture succeeded. Releases the hold this module placed once no failed capture is left.
     *
     * @param Order $order
     * @param string|int $transactionId
     * @param string|null $liftedStatus what lift() returned, when the hold was lifted for an invoice
     */
    public function release(Order $order, $transactionId, ?string $liftedStatus = null): void
    {
        $payment = $order->getPayment();
        $transactionId = (string)$transactionId;
        $succeeded = $this->list($order, self::KEY_SUCCEEDED);
        $redelivered = in_array($transactionId, $succeeded, true);
        $succeeded[] = $transactionId;
        $payment->setAdditionalInformation(self::KEY_SUCCEEDED, array_values(array_unique($succeeded)));

        $lifted = $liftedStatus !== null;
        if (!$lifted && !$this->isHeld($order)) {
            $this->forget($order);

            return;
        }

        $failed = $this->list($order, self::KEY_FAILED);
        $open = array_values(array_diff($failed, [$transactionId]));
        if ($open !== []) {
            $payment->setAdditionalInformation(self::KEY_FAILED, $open);
            if (!$redelivered || $open !== $failed) {
                $order->addStatusHistoryComment(__(
                    'Capture %1 succeeded, the order stays on hold: capture %2 is still failed',
                    $transactionId,
                    implode(', ', $open)
                ));
            }
            if ($lifted) {
                $this->restore($order, $liftedStatus);
            }

            return;
        }

        $held = (bool)$payment->getAdditionalInformation(self::KEY_HELD);
        $payment->unsAdditionalInformation(self::KEY_FAILED);
        $payment->unsAdditionalInformation(self::KEY_HELD);
        // A hold the merchant placed, or one from before the module kept track
        if (!$held) {
            if (!$redelivered) {
                $order->addStatusHistoryComment(__(
                    'Capture %1 succeeded, the order stays on hold: Qliro One did not place this hold',
                    $transactionId
                ));
            }
            if ($lifted) {
                $this->restore($order, $liftedStatus);
            }

            return;
        }

        if ($lifted) {
            // The invoice set its own status, the one from before the hold comes back
            if ($liftedStatus !== '') {
                $order->setStatus($liftedStatus);
            }
        } else {
            $this->own($order, static fn () => $order->unhold());
        }
        $order->addStatusHistoryComment(__('Capture %1 succeeded, the order is released from hold', $transactionId));
        $this->keepStatusAssigned($order);
    }

    /**
     * Magento creates no invoice for an order on hold. Lifts the hold and returns what release() or
     * restore() needs to settle it afterwards, or null when the order is not on hold.
     *
     * @param Order $order
     * @return string|null the status the order had before the hold
     */
    public function lift(Order $order): ?string
    {
        if (!$this->isHeld($order)) {
            return null;
        }
        $statusBefore = (string)$order->getHoldBeforeStatus();
        $this->own($order, static fn () => $order->unhold());

        return $statusBefore;
    }

    /**
     * Puts back a hold lift() took off, with the status from before the hold where the new state has it.
     *
     * @param Order $order
     * @param string $statusBefore
     */
    public function restore(Order $order, string $statusBefore): void
    {
        if (!$order->canHold()) {
            $failed = $this->list($order, self::KEY_FAILED);
            if ($failed !== []) {
                $order->addStatusHistoryComment(__(
                    'The order could not be put back on hold in state %1: capture %2 is still failed',
                    $order->getState(),
                    implode(', ', $failed)
                ));
            }
            $this->forget($order);

            return;
        }
        $this->own($order, static fn () => $order->hold());
        if (in_array($statusBefore, $this->assigned($order, (string)$order->getHoldBeforeState()), true)) {
            $order->setHoldBeforeStatus($statusBefore);
        }
    }

    /**
     * Ends the tracking: the order was held or released by someone else
     *
     * @param Order $order
     */
    public function forget(Order $order): void
    {
        $payment = $order->getPayment();
        if (!$payment) {
            return;
        }
        $payment->unsAdditionalInformation(self::KEY_FAILED);
        $payment->unsAdditionalInformation(self::KEY_HELD);
    }

    /**
     * Magento's unhold() puts back the status from before the hold whether or not the state still has it
     *
     * @param Order $order
     */
    private function keepStatusAssigned(Order $order): void
    {
        $state = (string)$order->getState();
        $status = (string)$order->getStatus();
        $default = (string)$order->getConfig()->getStateDefaultStatus($state);
        if ($default === '' || in_array($status, $this->assigned($order, $state), true)) {
            return;
        }
        $order->setStatus($default);
        $order->addStatusHistoryComment(__(
            'The status %1 the order had before the hold is not assigned to state %2, so the order gets %3',
            $status,
            $state,
            $default
        ));
    }

    private function own(Order $order, callable $action): void
    {
        $order->setData(self::OWN_ACTION, true);
        try {
            $action();
        } finally {
            $order->unsetData(self::OWN_ACTION);
        }
    }

    /**
     * @param Order $order
     * @param string $state
     * @return string[]
     */
    private function assigned(Order $order, string $state): array
    {
        return array_map('strval', array_keys($order->getConfig()->getStateStatuses($state)));
    }

    /**
     * @param Order $order
     * @param string $key
     * @return string[]
     */
    private function list(Order $order, string $key): array
    {
        return array_map('strval', (array)$order->getPayment()->getAdditionalInformation($key));
    }

    private function isHeld(Order $order): bool
    {
        return $order->getState() === Order::STATE_HOLDED;
    }
}
