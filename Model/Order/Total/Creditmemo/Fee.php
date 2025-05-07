<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Order\Total\Creditmemo;

use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Total\AbstractTotal;
use Qliro\QliroOne\Api\Admin\CreditMemo\InvoiceFeeTotalValidatorInterface;

class Fee extends AbstractTotal
{
    /**
     * @var InvoiceFeeTotalValidatorInterface
     */
    private InvoiceFeeTotalValidatorInterface $invoiceFeeTotalValidator;

    /**
     * @param InvoiceFeeTotalValidatorInterface $invoiceFeeTotalValidator
     * @param array $data
     */
    public function __construct(
        InvoiceFeeTotalValidatorInterface $invoiceFeeTotalValidator,
        array $data = []
    )
    {
        parent::__construct($data);
        $this->invoiceFeeTotalValidator = $invoiceFeeTotalValidator;
    }
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

        if (is_array($qlirooneFees) && $this->invoiceFeeTotalValidator->setCreditMemo($creditmemo)->validate(false)) {
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
