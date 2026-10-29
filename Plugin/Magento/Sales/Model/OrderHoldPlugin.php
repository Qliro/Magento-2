<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Plugin\Magento\Sales\Model;

use Magento\Sales\Model\Order;
use Qliro\QliroOne\Model\Method\QliroOne;
use Qliro\QliroOne\Model\OrderManagementStatus\Update\CaptureHold;

/**
 * A hold or release the module did not make ends its tracking of failed captures, so a hold the
 * merchant places later is never released by a capture success
 */
class OrderHoldPlugin
{
    /**
     * @var CaptureHold
     */
    private $captureHold;

    /**
     * @param CaptureHold $captureHold
     */
    public function __construct(CaptureHold $captureHold)
    {
        $this->captureHold = $captureHold;
    }

    /**
     * @param Order $subject
     * @param Order $result
     * @return Order
     */
    public function afterHold(Order $subject, $result)
    {
        $this->forgetUnlessOwn($subject);

        return $result;
    }

    /**
     * @param Order $subject
     * @param Order $result
     * @return Order
     */
    public function afterUnhold(Order $subject, $result)
    {
        $this->forgetUnlessOwn($subject);

        return $result;
    }

    private function forgetUnlessOwn(Order $order): void
    {
        if ($order->getData(CaptureHold::OWN_ACTION)) {
            return;
        }
        $payment = $order->getPayment();
        if ($payment && $payment->getMethod() === QliroOne::PAYMENT_METHOD_CHECKOUT_CODE) {
            $this->captureHold->forget($order);
        }
    }
}
