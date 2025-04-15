<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Block\Adminhtml\Sales\Order\Creditmemo;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Qliro\QliroOne\Model\Fee;

class Totals extends Template
{
    /**
     * @var Fee
     */
    private $fee;

    /**
     * Totals constructor.
     *
     * @param Context $context
     * @param Fee $fee
     * @param array $data
     */
    public function __construct(
        Context $context,
        Fee $fee,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->fee = $fee;
    }

    /**
     * Initialize payment fee totals
     *
     * @return $this
     */
    public function initTotals()
    {
        /** @var \Magento\Sales\Block\Adminhtml\Order\Creditmemo\Totals $parent */
        $parent = $this->getParentBlock();

        // invoice fee forced to be added to the first refund.
        // If we do not the first refund, we make sure
        // that invoice fee will not be added to the calculation
        $isFirstCreditMemo = true;
        if ($parent->getOrder()->getCreditmemosCollection()->count() > 0) {
            $isFirstCreditMemo = false;
        }

        /** @var \Magento\Sales\Model\Order\Creditmemo $creditMemo */
        $creditMemo = $parent->getCreditmemo();

        $qlirooneFees = $creditMemo->getOrder()->getPayment()->getAdditionalInformation('qliroone_fees');
        if (is_array($qlirooneFees)) {
            foreach ($qlirooneFees as $qlirooneFee) {
                if (!$isFirstCreditMemo) {
                    $qlirooneFee['PricePerItemIncVat'] = 0;
                    $qlirooneFee['PricePerItemExVat'] = 0;
                }
                $fee = $this->fee->feeToFeeObject($qlirooneFee);
                $parent->addTotalBefore($fee, 'sub_total');
            }
        }

        return $this;
    }
}
