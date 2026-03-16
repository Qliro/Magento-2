<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Block\Adminhtml\Sales\Order;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Qliro\QliroOne\Model\Fee;

class Totals extends Template
{
    /**
     * Class constructor
     *
     * @param Context $context
     * @param Fee $fee
     * @param array $data
     */
    public function __construct(
        Context $context,
        private readonly Fee $fee,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    /**
     * Initialize payment fee totals
     *
     * @return $this
     */
    public function initTotals()
    {
        /** @var \Magento\Sales\Block\Adminhtml\Order\Totals $parent */
        $parent = $this->getParentBlock();

        /** @var \Magento\Sales\Model\Order $order */
        $order = $parent->getOrder();

        $qlirooneFees = $order->getPayment()->getAdditionalInformation('qliroone_fees');
        if (is_array($qlirooneFees)) {
            foreach ($qlirooneFees as $qlirooneFee) {
                $fee = $this->fee->feeToFeeObject($qlirooneFee);
                $parent->addTotalBefore($fee, 'sub_total');
            }
        }

        return $this;
    }
}
