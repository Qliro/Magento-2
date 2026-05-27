<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Order\Total\Creditmemo;

use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Total\AbstractTotal;
use Qliro\QliroOne\Api\Admin\CreditMemo\InvoiceFeeTotalValidatorInterface as InvoiceFeeTotalValidator;

class Fee extends AbstractTotal
{
    /**
     * Class constructor
     *
     * @param InvoiceFeeTotalValidator            $invoiceFeeTotalValidator
     * @param array $data
     */
    public function __construct(
        private readonly InvoiceFeeTotalValidator $invoiceFeeTotalValidator,
        array $data = []
    ) {
        parent::__construct($data);
    }

    /**
     * Collect totals
     *
     * @param Creditmemo $creditmemo
     * @return $this
     */
    public function collect(Creditmemo $creditmemo): static
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
