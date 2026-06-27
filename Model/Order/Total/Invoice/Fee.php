<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Order\Total\Invoice;

use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Invoice\Total\AbstractTotal;

class Fee extends AbstractTotal
{
    /**
     * Collect totals
     *
     * @param Invoice $invoice
     * @return $this
     */
    public function collect(Invoice $invoice): static
    {
        /** @var \Magento\Sales\Model\Order $order */
        $order = $invoice->getOrder();
        if ((float)$order->getTotalInvoiced() > 0) {
            return $this;
        }

        $qlirooneFees = $order->getPayment()->getAdditionalInformation('qliroone_fees');
        $qliroFeeTotal = 0;
        if (is_array($qlirooneFees)) {
            /** @var array $qlirooneFee */
            foreach ($qlirooneFees as $qlirooneFee) {
                $qliroFeeTotal += $qlirooneFee["PricePerItemIncVat"];
            }
        }
        if ($qliroFeeTotal > 0) {
            $invoice->setGrandTotal($invoice->getGrandTotal() + $qliroFeeTotal);
            $invoice->setBaseGrandTotal($invoice->getBaseGrandTotal() + $qliroFeeTotal);
        }

        return $this;
    }
}