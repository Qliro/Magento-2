<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Block\Adminhtml\Sales\Order\Creditmemo;

use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use Magento\Sales\Api\Data\CreditmemoInterface;
use Qliro\QliroOne\Model\Fee;
use Qliro\QliroOne\Api\Admin\CreditMemo\InvoiceFeeTotalValidatorInterface;

class Totals extends Template
{
    /**
     * @var Fee
     */
    private $fee;

    /**
     * @var InvoiceFeeTotalValidatorInterface
     */
    private InvoiceFeeTotalValidatorInterface $invoiceFeeTotalValidator;

    /**
     * Totals constructor.
     *
     * @param Context $context
     * @param Fee $fee
     * @param InvoiceFeeTotalValidatorInterface $invoiceFeeTotalValidator
     * @param array $data
     */
    public function __construct(
        Context $context,
        Fee $fee,
        InvoiceFeeTotalValidatorInterface $invoiceFeeTotalValidator,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->fee = $fee;
        $this->invoiceFeeTotalValidator = $invoiceFeeTotalValidator;
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

        /** @var CreditmemoInterface $creditMemo */
        $creditMemo = $parent->getCreditmemo();

        $qlirooneFees = $creditMemo->getOrder()->getPayment()->getAdditionalInformation('qliroone_fees');
        if (is_array($qlirooneFees)) {
            $canAddTotalRow = $this->invoiceFeeTotalValidator->setCreditMemo($creditMemo)->validate();
            foreach ($qlirooneFees as $qlirooneFee) {
                if (!$canAddTotalRow) {
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
