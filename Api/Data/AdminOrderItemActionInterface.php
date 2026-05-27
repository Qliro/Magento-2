<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Admin Order Item Action interface
 *
 * @api
 */
interface AdminOrderItemActionInterface extends QliroOrderItemInterface
{
    /**
     * Get action type
     *
     * @return string
     */
    public function getActionType(): string;

    /**
     * Get payment transaction ID
     *
     * @return int|null
     */
    public function getPaymentTransactionId(): ?int;

    /**
     * Set action type
     *
     * @param string $value
     * @return $this
     */
    public function setActionType(string $value): static;

    /**
     * Set payment transaction ID
     *
     * @param int|null $value
     * @return $this
     */
    public function setPaymentTransactionId(?int $value): static;
}
