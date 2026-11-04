<?php

declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin\CreditMemo;

use Magento\Sales\Api\Data\CreditmemoInterface;
use Qliro\QliroOne\Api\Admin\CreditMemo\InvoiceFeeTotalValidatorInterface;

class InvoiceFeeTotalValidator implements InvoiceFeeTotalValidatorInterface
{
    /**
     * @var CreditmemoInterface|null
     */
    protected ?CreditmemoInterface $creditMemo = null;

    /**
     * @var float|null
     */
    private ?float $totalFee = null;

    /**
     * The fee sits on one invoice but belongs to the order, so it is refunded only with the whole order.
     * Every amount is in the order currency, the fee's, and compared in minor units.
     *
     * @inheritDoc
     */
    public function validate(bool $feeIsAddedAsTotal = true, bool $useQtyRefundedOnly = false)
    {
        $creditMemo = $this->getCreditMemo();
        if (!$creditMemo || !$creditMemo->getInvoice()) {
            return false;
        }

        $fee = $this->toMinorUnits($this->getOrderFeesTotal());
        if ($fee === 0) {
            return false;
        }

        $order = $creditMemo->getOrder();
        $grandTotal = $this->toMinorUnits((float)$order->getGrandTotal());
        if ($useQtyRefundedOnly) {
            return $this->toMinorUnits((float)$order->getTotalRefunded()) >= $grandTotal;
        }

        $refunded = $this->refundedBefore($creditMemo) + $this->toMinorUnits((float)$creditMemo->getGrandTotal());
        if ($feeIsAddedAsTotal) {
            $refunded -= $fee;
        }

        return $refunded >= $grandTotal - $fee;
    }

    /**
     * The order's refunded total holds a saved memo itself, so a saved one counts the memos before it
     *
     * @param CreditmemoInterface $creditMemo
     * @return int
     */
    private function refundedBefore(CreditmemoInterface $creditMemo): int
    {
        if (!$creditMemo->getEntityId()) {
            return $this->toMinorUnits((float)$creditMemo->getOrder()->getTotalRefunded());
        }

        $refunded = 0;
        foreach ($creditMemo->getOrder()->getCreditmemosCollection() as $earlier) {
            if ((int)$earlier->getEntityId() < (int)$creditMemo->getEntityId()) {
                $refunded += $this->toMinorUnits((float)$earlier->getGrandTotal());
            }
        }

        return $refunded;
    }

    /**
     * @param float $amount
     * @return int
     */
    private function toMinorUnits(float $amount): int
    {
        return (int)round($amount * 100);
    }

    /**
     * Sum of the fees Qliro charged, VAT included, in the order currency
     *
     * @return float
     */
    private function getOrderFeesTotal(): float
    {
        if ($this->totalFee === null) {
            $this->totalFee = 0.0;
            $fees = $this->getCreditMemo()->getOrder()->getPayment()->getAdditionalInformation('qliroone_fees');
            foreach (is_array($fees) ? $fees : [] as $fee) {
                $this->totalFee += (float)($fee['PricePerItemIncVat'] ?? 0);
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
        $this->totalFee = null;

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
