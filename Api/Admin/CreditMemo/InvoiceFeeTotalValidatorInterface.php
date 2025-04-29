<?php

namespace Qliro\QliroOne\Api\Admin\CreditMemo;

use Magento\Sales\Api\Data\CreditmemoInterface;

interface InvoiceFeeTotalValidatorInterface
{
    /**
     * Validates the given data based on the provided flag.
     *
     * @param bool $useQtyRefundedOnly Flag to determine if validation should only consider refunded quantities.
     * @return bool
     *
     */
    public function validate(bool $useQtyRefundedOnly = false);

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
