<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Admin Transaction Response interface
 *
 * @api
 */
interface AdminTransactionResponseInterface
{
    const string TYPE_UPDATE = 'UpdateItemsResponse';
    const string TYPE_UPDATE_WITH_REVERSAL = 'UpdateItemsWithReversalResponse';

    /**
     * Get payment transaction ID
     *
     * @return int|null
     */
    public function getPaymentTransactionId(): ?int;

    /**
     * Get transaction status
     *
     * @return string|null
     */
    public function getStatus(): ?string;

    /**
     * Get response type
     *
     * @return string|null
     */
    public function getType(): ?string;

    /**
     * Get reversal payment transaction ID
     *
     * @return int|null
     */
    public function getReversalPaymentTransactionId(): ?int;

    /**
     * Get reversal payment transaction status
     *
     * @return string|null
     */
    public function getReversalPaymentTransactionStatus(): ?string;

    /**
     * Set payment transaction ID
     *
     * @param mixed $value
     * @return static
     */
    public function setPaymentTransactionId(mixed $value): static;

    /**
     * Set transaction status
     *
     * @param mixed $value
     * @return static
     */
    public function setStatus(mixed $value): static;

    /**
     * Set response type
     *
     * @param mixed $value
     * @return static
     */
    public function setType(mixed $value): static;

    /**
     * Set reversal payment transaction ID
     *
     * @param mixed $value
     * @return static
     */
    public function setReversalPaymentTransactionId(mixed $value): static;

    /**
     * Set reversal payment transaction status
     *
     * @param mixed $value
     * @return static
     */
    public function setReversalPaymentTransactionStatus(mixed $value): static;
}
