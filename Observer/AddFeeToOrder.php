<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Observer;

use Magento\Framework\Event\Observer as EventObserver;
use Magento\Framework\Event\ObserverInterface;

class AddFeeToOrder implements ObserverInterface
{
    /**
     * Set payment fee to order
     *
     * @param EventObserver $observer
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer): void
    {
        //Set fee data to order
        $order = $observer->getOrder();
        $qlirooneFees = $order->getPayment()->getAdditionalInformation('qliroone_fees');
        if (is_array($qlirooneFees)) {
            foreach ($qlirooneFees as $qlirooneFee) {
                //update totals
                $order->setGrandTotal($order->getGrandTotal() + $qlirooneFee["PricePerItemIncVat"]);
            }
        }
    }
}
