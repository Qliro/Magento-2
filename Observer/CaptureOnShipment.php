<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Qliro\QliroOne\Api\Admin\OrderServiceInterface;
use Qliro\QliroOne\Model\Method\QliroOne;

/**
 * Adds the invoice to the payment object before the capture event fires,
 * so that the capture command can access it.
 */
class CaptureOnShipment implements ObserverInterface
{
    public function __construct(
        private readonly OrderServiceInterface $qliroAdminService
    ) {
    }

    public function execute(Observer $observer): void
    {
        /** @var \Magento\Sales\Model\Order\Shipment $shipment */
        $shipment = $observer->getEvent()->getShipment();
        $order    = $shipment->getOrder();
        $payment  = $order->getPayment();

        if ($payment->getMethod() === QliroOne::PAYMENT_METHOD_CHECKOUT_CODE) {
            $this->qliroAdminService->captureByShipment($shipment);
        }
    }
}
