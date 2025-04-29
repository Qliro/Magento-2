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
     * @inheritDoc
     */
    public function validate(bool $useQtyRefundedOnly = false)
    {
        if (!$this->getCreditMemo()) {
            return false;
        }

        $result = true;
        foreach ($this->getCreditMemo()->getItems() as $creditMemoItem) {
            if ($useQtyRefundedOnly) {
                if ($creditMemoItem->getOrderItem()->getQtyRefunded() != $creditMemoItem->getOrderItem()->getQtyInvoiced()) {
                    $result = false;
                    break;
                }
            } else {
                if (($creditMemoItem->getQty() + $creditMemoItem->getOrderItem()->getQtyRefunded()
                    != $creditMemoItem->getOrderItem()->getQtyInvoiced())) {
                    $result = false;
                    break;
                }
            }

        }

        if ($result &&
            $this->getCreditMemo()->getOrder()->getShippingRefunded() == 0 &&
            ($this->getCreditMemo()->getShippingInclTax() != $this->getCreditMemo()->getOrder()->getShippingInclTax())) {
            $result = false;
        }

        return $result;
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
