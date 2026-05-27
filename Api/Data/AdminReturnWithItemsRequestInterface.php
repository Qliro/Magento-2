<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Admin Return With Items Request interface
 *
 * @api
 */
interface AdminReturnWithItemsRequestInterface
{
    /**
     * Get merchant API key
     *
     * @return string
     */
    public function getMerchantApiKey(): string;

    /**
     * Get payment reference
     *
     * @return int
     */
    public function getPaymentReference(): int;

    /**
     * Get request ID
     *
     * @return string
     */
    public function getRequestId(): string;

    /**
     * Get currency code
     *
     * @return string
     */
    public function getCurrency(): string;

    /**
     * Get order items
     *
     * @return QliroOrderItemInterface[]
     */
    public function getOrderItems(): array;

    /**
     * Get fee items
     *
     * @return QliroOrderItemInterface[]
     */
    public function getFees(): array;

    /**
     * Get discount items
     *
     * @return QliroOrderItemInterface[]
     */
    public function getDiscounts(): array;

    /**
     * Get Qliro order ID
     *
     * @return int
     */
    public function getOrderId(): int;

    /**
     * Get returns data
     *
     * @return array
     */
    public function getReturns(): array;

    /**
     * Get payment transaction ID
     *
     * @return int
     */
    public function getPaymentTransactionId(): int;

    /**
     * Set merchant API key
     *
     * @param string $value
     * @return $this
     */
    public function setMerchantApiKey(string $value): static;

    /**
     * Set payment reference
     *
     * @param int $value
     * @return $this
     */
    public function setPaymentReference(int $value): static;

    /**
     * Set request ID
     *
     * @param string $value
     * @return $this
     */
    public function setRequestId(string $value): static;

    /**
     * Set currency code
     *
     * @param string $value
     * @return $this
     */
    public function setCurrency(string $value): static;

    /**
     * Set order items
     *
     * @param QliroOrderItemInterface[] $orderItems
     * @return $this
     */
    public function setOrderItems(array $orderItems): static;

    /**
     * Set fee items
     *
     * @param QliroOrderItemInterface[] $value
     * @return $this
     */
    public function setFees(array $value): static;

    /**
     * Set discount items
     *
     * @param QliroOrderItemInterface[] $value
     * @return $this
     */
    public function setDiscounts(array $value): static;

    /**
     * Set Qliro order ID
     *
     * @param int $value
     * @return $this
     */
    public function setOrderId(int $value): static;

    /**
     * Set returns data
     *
     * @param array $value
     * @return $this
     */
    public function setReturns(array $value): static;

    /**
     * Set payment transaction ID
     *
     * @param int $value
     * @return $this
     */
    public function setPaymentTransactionId(int $value): static;
}
