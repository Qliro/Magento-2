<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Order\Total\Creditmemo;

use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Total\AbstractTotal;

class Fee extends AbstractTotal
{
    /**
     * Collect totals
     *
     * @param Creditmemo $creditmemo
     * @return $this
     */
    public function collect(Creditmemo $creditmemo)
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $creditmemo->getOrder();
        $qlirooneFees = $order->getPayment()->getAdditionalInformation('qliroone_fees');
        $qliroFeeTotal = 0;

        // invoice fee forced to be added to the first refund.
        // If we do not the first refund, we make sure
        // that invoice fee will not be added to the calculation
        if (is_array($qlirooneFees) && $order->getCreditmemosCollection()->count() === 0) {
            foreach ($qlirooneFees as $qlirooneFee) {
                $qliroFeeTotal += $qlirooneFee["PricePerItemIncVat"];
            }
        }
        if ($qliroFeeTotal > 0) {
            $creditmemo->setGrandTotal($creditmemo->getGrandTotal() + $qliroFeeTotal);
            $creditmemo->setBaseGrandTotal($creditmemo->getBaseGrandTotal() + $qliroFeeTotal);
        }

        return $this;
    }
}
