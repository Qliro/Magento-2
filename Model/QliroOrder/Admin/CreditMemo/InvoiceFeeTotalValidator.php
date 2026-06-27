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
     * @inheritDoc
     */
    public function validate(bool $feeIsAddedAsTotal = true, bool $useQtyRefundedOnly = false): bool
    {
        if (!$this->getCreditMemo()) {
            return false;
        }

        if ($this->getOrderFeesTotal() == 0) {
            return false;
        }

        // Credit memos created from the order (not tied to a specific invoice) have no
        // invoice reference. Invoice-level fee validation cannot be performed in that case.
        if ($this->getCreditMemo()->getInvoice() === null) {
            return false;
        }

        $creditMemo = $this->getCreditMemo();
        $order = $creditMemo->getOrder();
        $fee = $this->getOrderFeesTotal();
        $orderGoodsTotal = (float)$order->getGrandTotal() - $fee;
        $alreadyRefundedGoods = (float)$order->getTotalRefunded();

        $thisMemoGoods = $feeIsAddedAsTotal
            ? (float)$creditMemo->getGrandTotal() - $fee
            : (float)$creditMemo->getGrandTotal();

        return bccomp(
            (string) ($alreadyRefundedGoods + $thisMemoGoods),
            (string) $orderGoodsTotal,
            2
        ) !== -1;
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
    private function getOrderFeesTotal(): float
    {
        if ($this->totalFee === null) {
            $this->totalFee = 0.0;
            $qlirooneFees = $this->getCreditMemo()->getOrder()->getPayment()->getAdditionalInformation('qliroone_fees');
            if (is_array($qlirooneFees)) {
                foreach ($qlirooneFees as $qlirooneFee) {
                    if (is_array($qlirooneFee)) {
                        $this->totalFee += floatval($qlirooneFee['PricePerItemIncVat'] ?? 0);
                    }
                }
            }
        }

        return $this->totalFee;
    }

    /**
     * @inheritDoc
     */
    public function setCreditMemo(CreditmemoInterface $creditMemo): static
    {
        $this->creditMemo = $creditMemo;
        $this->totalFee = null;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getCreditMemo(): ?CreditmemoInterface
    {
        return $this->creditMemo;
    }
}
