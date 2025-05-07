<?php

namespace Qliro\QliroOne\Api\Admin\CreditMemo;

use Magento\Sales\Api\Data\CreditmemoInterface;

interface InvoiceFeeTotalValidatorInterface
{
    /**
     * Validates the provided data or configuration.
     *
     * @param bool $feeIsAddedAsTotal Indicates whether the fee is added as a total.
     * @param bool $useQtyRefundedOnly Flag to determine if validation should only consider refunded quantities.
     * @return bool
     *
     */
    public function validate(bool $feeIsAddedAsTotal = true, bool $useQtyRefundedOnly = false);

    /**
     * Sets the credit memo object.
     *
     * @param CreditmemoInterface $creditMemo The credit memo instance to be set.
     * @return InvoiceFeeTotalValidatorInterface
     *
     */
    public function setCreditMemo(CreditmemoInterface $creditMemo);

    /**
     * Retrieves the credit memo object.
     *
     * @return CreditmemoInterface The credit memo instance
     *
     */
    public function getCreditMemo();
}
