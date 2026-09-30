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
 * As capture event doesn't contain the invoice (it's meant to capture amount only), this observer
 * adds the invoice to the payment object for later retrieval
 */
class CaptureOnShipment implements ObserverInterface
{
    /**
     * @var \Qliro\QliroOne\Model\Management
     */
    private $qliroManagement;

    /**
     * Inject dependencies
     *
     * @param \Qliro\QliroOne\Model\Management $qliroManagement
     */
    public function __construct(
        \Qliro\QliroOne\Model\Management $qliroManagement
    ) {
        $this->qliroManagement = $qliroManagement;
    }

    /**
     * @param Observer $observer
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order\Shipment $shipment */
        $shipment = $observer->getEvent()->getShipment();

        $order = $shipment->getOrder();
        $payment = $order->getPayment();

        if ($payment->getMethod() == QliroOne::PAYMENT_METHOD_CHECKOUT_CODE) {
            $this->qliroManagement->captureByShipment($shipment);
        }
    }
}
