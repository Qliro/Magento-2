<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Admin Order interface.
 *
 * @api
 */
interface AdminOrderInterface
{
    /**
     * @return int
     */
    public function getOrderId(): int;

    /**
     * @return string
     */
    public function getMerchantReference(): string;

    /**
     * @return float
     */
    public function getTotalPrice(): float;

    /**
     * @return string
     */
    public function getCountry(): string;

    /**
     * @return string
     */
    public function getCurrency(): string;

    /**
     * @return string
     */
    public function getLanguage(): string;

    /**
     * @return bool
     */
    public function getSignupForNewsletter(): bool;

    /**
     * @return mixed
     */
    public function getIdentityVerification(): mixed;

    /**
     * @return mixed
     */
    public function getCustomer(): mixed;

    /**
     * @return mixed
     */
    public function getBillingAddress(): mixed;

    /**
     * @return mixed
     */
    public function getShippingAddress(): mixed;

    /**
     * @return AdminOrderItemActionInterface[]
     */
    public function getOrderItemActions(): array;

    /**
     * @return AdminOrderPaymentTransactionInterface[]
     */
    public function getPaymentTransactions(): array;

    /**
     * @param int $value
     * @return static
     */
    public function setOrderId(int $value): static;

    /**
     * @param string $value
     * @return static
     */
    public function setMerchantReference(string $value): static;

    /**
     * @param float $value
     * @return static
     */
    public function setTotalPrice(float $value): static;

    /**
     * @param string $value
     * @return static
     */
    public function setCountry(string $value): static;

    /**
     * @param string $value
     * @return static
     */
    public function setCurrency(string $value): static;

    /**
     * @param string $value
     * @return static
     */
    public function setLanguage(string $value): static;

    /**
     * @param bool $value
     * @return static
     */
    public function setSignupForNewsletter(bool $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setIdentityVerification(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setCustomer(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setBillingAddress(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setShippingAddress(mixed $value): static;

    /**
     * @param AdminOrderItemActionInterface[] $value
     * @return static
     */
    public function setOrderItemActions(array $value): static;

    /**
     * @param AdminOrderPaymentTransactionInterface[] $value
     * @return static
     */
    public function setPaymentTransactions(array $value): static;
}
