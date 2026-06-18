<?php

declare(strict_types=1);

namespace Qliro\QliroOne\Plugin\Adminhtml;

use Magento\Sales\Block\Adminhtml\Order\Creditmemo\Totals;
use Magento\Sales\Model\Order\Creditmemo;

class ChangeCreditMemoTotals
{
    /**
     * Modifies the shipping-related amounts of the credit memo if it consists solely of virtual items.
     *
     * @param Totals $subject The totals object (not used directly in this method).
     * @param Creditmemo $creditmemo The credit memo to modify.
     * @return Creditmemo The updated credit memo with shipping-related amounts set to zero,
     * if it contains only virtual items; otherwise, the original credit memo.
     */
    public function afterGetCreditmemo(Totals $subject, Creditmemo $creditmemo): Creditmemo
    {
        if ($creditmemo->getId() || !$this->isVirtualOnlyCreditMemo($creditmemo)) {
            return $creditmemo;
        }

        $creditmemo->setShippingAmount(0);
        $creditmemo->setBaseShippingAmount(0);
        $creditmemo->setShippingInclTax(0);
        $creditmemo->setBaseShippingInclTax(0);
        $creditmemo->setShippingTaxAmount(0);
        $creditmemo->setBaseShippingTaxAmount(0);

        return $creditmemo;
    }

    /**
     * Determines if the given credit memo is exclusively for virtual items.
     *
     * @param Creditmemo $creditmemo The credit memo to evaluate.
     * @return bool Returns true if the credit memo contains only virtual items and has at least one item; otherwise, false.
     */
    private function isVirtualOnlyCreditMemo(Creditmemo $creditmemo): bool
    {
        $hasItems = false;

        foreach ($creditmemo->getAllItems() as $item) {
            if ((float)$item->getQty() <= 0) {
                continue;
            }

            $hasItems = true;

            $orderItem = $item->getOrderItem();

            if (!$orderItem || !$orderItem->getIsVirtual()) {
                return false;
            }
        }

        return $hasItems;
    }
}
