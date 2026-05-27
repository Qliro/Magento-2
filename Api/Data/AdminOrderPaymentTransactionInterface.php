<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Admin Order Payment Transaction interface
 *
 * @api
 */
interface AdminOrderPaymentTransactionInterface
{
    /**
     * Get payment transaction ID
     *
     * @return int
     */
    public function getPaymentTransactionId(): int;

    /**
     * Get transaction type
     *
     * @return string
     */
    public function getType(): string;

    /**
     * Get transaction amount
     *
     * @return float
     */
    public function getAmount(): float;

    /**
     * Get currency code
     *
     * @return string
     */
    public function getCurrency(): string;

    /**
     * Get transaction status
     *
     * @return string
     */
    public function getStatus(): string;

    /**
     * Get payment method name
     *
     * @return string
     */
    public function getPaymentMethodName(): string;

    /**
     * Set payment transaction ID
     *
     * @param int $value
     * @return $this
     */
    public function setPaymentTransactionId(int $value): static;

    /**
     * Set transaction type
     *
     * @param string $value
     * @return $this
     */
    public function setType(string $value): static;

    /**
     * Set transaction amount
     *
     * @param float $value
     * @return $this
     */
    public function setAmount(float $value): static;

    /**
     * Set currency code
     *
     * @param string $value
     * @return $this
     */
    public function setCurrency(string $value): static;

    /**
     * Set transaction status
     *
     * @param string $value
     * @return $this
     */
    public function setStatus(string $value): static;

    /**
     * Set a payment method name
     *
     * @param string $value
     * @return $this
     */
    public function setPaymentMethodName(string $value): static;
}
