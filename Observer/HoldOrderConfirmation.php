<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Qliro\QliroOne\Model\Method\QliroOne;

/**
 * Keeps core from sending the order confirmation at placement, it goes out once Qliro completes the order
 */
class HoldOrderConfirmation implements ObserverInterface
{
    // Tells our hold apart from another extension turning the flag off
    public const HELD = 'qliroone_confirmation_held';

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getOrder();

        if ($order
            && $order->getCanSendNewEmailFlag()
            && $order->getPayment()?->getMethod() === QliroOne::PAYMENT_METHOD_CHECKOUT_CODE
        ) {
            $order->setCanSendNewEmailFlag(false);
            $order->setData(self::HELD, true);
        }
    }
}
