<?php

namespace Qliro\QliroOne\Model\QliroOrder\Admin\CreditMemo;

use Magento\Sales\Api\Data\CreditmemoInterface;
use Qliro\QliroOne\Api\Admin\CreditMemo\InvoiceFeeTotalValidatorInterface;

class InvoiceFeeTotalValidator implements InvoiceFeeTotalValidatorInterface
{
    /**
     * @var CreditmemoInterface $creditMemo
     */
    protected CreditmemoInterface $creditMemo;

    /**
     * @var float
     */
    private $totalFee;

    /**
     * @inheritDoc
     */
    public function validate(bool $feeIsAddedAsTotal = true, bool $useQtyRefundedOnly = false)
    {
        if (!$this->getCreditMemo()) {
            return false;
        }

        if (!$this->getCreditMemo()->getInvoice()) {
            return false;
        }

        if ($this->getOrderFeesTotal() == 0) {
            return false;
        }

        $order = $this->getCreditMemo()->getOrder();

        if ($useQtyRefundedOnly) {
            return bccomp(
                    $order->getBaseTotalRefunded(),
                    $order->getGrandTotal()
                ) != -1;
        }

        /*
         * The invoice fee is an order-level charge that is placed on a single invoice (the first
         * one created). It must therefore be refunded only once the whole order is fully refunded,
         * not when an individual invoice happens to be fully refunded while other items remain
         * (e.g. a virtual product captured on its own invoice in a split-capture order).
         */
        $orderGrandTotal = floatval($order->getGrandTotal()) - $this->getOrderFeesTotal();

        $totalRefunded = floatval($order->getBaseTotalRefunded());
        $totalCreditMemo = floatval($this->getCreditMemo()->getGrandTotal());
        $fee = $this->getOrderFeesTotal();
        $orderTotalRefunded = $feeIsAddedAsTotal
            ? $totalRefunded + $totalCreditMemo - $fee
            : $totalRefunded + $totalCreditMemo;

        if (bccomp($orderTotalRefunded, $orderGrandTotal) != -1) {
            return true;
        }

        return false;
    }

    /**
     * Calculates and retrieves the total fees associated with an order.
     *
     * If the fees have already been calculated and cached in the $totalFee property,
     * the method returns this value directly. Otherwise, it calculates the total
     * by summing up the prices (including VAT) from the payment's additional information,
     * caches the result, and then returns it.
     *
     * @return float The total fees for the order, including VAT.
     */
    private function getOrderFeesTotal()
    {
        if (!$this->totalFee) {
            $feeTotal = floatval(0);
            $qlirooneFees = $this->getCreditMemo()->getOrder()->getPayment()->getAdditionalInformation('qliroone_fees');
            if (!is_array($qlirooneFees) || !count($qlirooneFees)) {
                $this->totalFee = $feeTotal;
                return $this->totalFee;
            }

            foreach ($qlirooneFees as $qlirooneFee) {
                $this->totalFee = $this->totalFee + floatval($qlirooneFee['PricePerItemIncVat']);
            }

        }

        return $this->totalFee;
    }

    /**
     * @inheritDoc
     */
    public function setCreditMemo(CreditmemoInterface $creditMemo)
    {
        $this->creditMemo = $creditMemo;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getCreditMemo()
    {
        return $this->creditMemo;
    }
}
