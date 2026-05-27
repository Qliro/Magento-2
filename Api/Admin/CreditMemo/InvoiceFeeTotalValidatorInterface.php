<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Admin\CreditMemo;

use Magento\Sales\Api\Data\CreditmemoInterface;

/**
 * Invoice fee total validator interface for credit memos
 *
 * @api
 */
interface InvoiceFeeTotalValidatorInterface
{
    /**
     * Validate whether the invoice fee can be refunded given the current credit memo state
     *
     * @param bool $feeIsAddedAsTotal
     * @param bool $useQtyRefundedOnly
     * @return bool
     */
    public function validate(bool $feeIsAddedAsTotal = true, bool $useQtyRefundedOnly = false): bool;

    /**
     * Set the credit memo to validate against
     *
     * @param CreditmemoInterface $creditMemo
     * @return static
     */
    public function setCreditMemo(CreditmemoInterface $creditMemo): static;

    /**
     * Get the credit memo being validated
     *
     * @return CreditmemoInterface|null
     */
    public function getCreditMemo(): ?CreditmemoInterface;
}
