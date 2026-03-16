<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Order\Total\Pdf;

use Magento\Sales\Model\Order\Pdf\Total\DefaultTotal;

class Fee extends DefaultTotal
{

    /**
     * Get totals for display on PDF
     *
     * @return array
     */
    public function getTotalsForDisplay()
    {
        $qlirooneFees = $this->getOrder()->getPayment()->getAdditionalInformation('qliroone_fees');
        $fontSize = $this->getFontSize() ? $this->getFontSize() : 7;
        $total = null;
        if (is_array($qlirooneFees)) {
            foreach ($qlirooneFees as $qlirooneFee) {
                $amount = $this->getOrder()->formatPriceTxt($qlirooneFee["PricePerItemIncVat"]);
                $title = __($this->getTitle());
                $label = $title . ' (' . $qlirooneFee["Description"] . ')';
                $total[] = ['amount' => $amount, 'label' => $label, 'font_size' => $fontSize];
            }
        }
        return $total ? $total : [];
    }
}
