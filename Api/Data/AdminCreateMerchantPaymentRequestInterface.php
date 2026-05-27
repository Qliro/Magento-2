<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Admin Create Merchant Payment Request interface
 *
 * @api
 */
interface AdminCreateMerchantPaymentRequestInterface
{
    /**
     * Get request ID
     *
     * @return string
     */
    public function getRequestId(): string;

    /**
     * Get merchant API key
     *
     * @return string
     */
    public function getMerchantApiKey(): string;

    /**
     * Get merchant reference
     *
     * @return string
     */
    public function getMerchantReference(): string;

    /**
     * Get currency code
     *
     * @return string
     */
    public function getCurrency(): string;

    /**
     * Get country code
     *
     * @return string
     */
    public function getCountry(): string;

    /**
     * Get language code
     *
     * @return string
     */
    public function getLanguage(): string;

    /**
     * Get order management status push URL
     *
     * @return string
     */
    public function getMerchantOrderManagementStatusPushUrl(): string;

    /**
     * Get order items
     *
     * @return QliroOrderItemInterface[]
     */
    public function getOrderItems(): array;

    /**
     * Get customer data
     *
     * @return MerchantPaymentCustomerInterface|null
     */
    public function getCustomer(): ?MerchantPaymentCustomerInterface;

    /**
     * Get billing address
     *
     * @return array|null
     */
    public function getBillingAddress(): ?array;

    /**
     * Get shipping address
     *
     * @return array|null
     */
    public function getShippingAddress(): ?array;

    /**
     * Get payment method
     *
     * @return MerchantPaymentPaymentMethodInterface|null
     */
    public function getPaymentMethod(): ?MerchantPaymentPaymentMethodInterface;

    /**
     * Set request ID
     *
     * @param string $value
     * @return $this
     */
    public function setRequestId(string $value): static;

    /**
     * Set merchant API key
     *
     * @param string $value
     * @return $this
     */
    public function setMerchantApiKey(string $value): static;

    /**
     * Set merchant reference
     *
     * @param string $value
     * @return $this
     */
    public function setMerchantReference(string $value): static;

    /**
     * Set currency code
     *
     * @param string $value
     * @return $this
     */
    public function setCurrency(string $value): static;

    /**
     * Set country code
     *
     * @param string $value
     * @return $this
     */
    public function setCountry(string $value): static;

    /**
     * Set language code
     *
     * @param string $value
     * @return $this
     */
    public function setLanguage(string $value): static;

    /**
     * Set order management status push URL
     *
     * @param string $value
     * @return $this
     */
    public function setMerchantOrderManagementStatusPushUrl(string $value): static;

    /**
     * Set order items
     *
     * @param QliroOrderItemInterface[] $value
     * @return $this
     */
    public function setOrderItems(array $value): static;

    /**
     * Set customer data
     *
     * @param MerchantPaymentCustomerInterface $value
     * @return $this
     */
    public function setCustomer(MerchantPaymentCustomerInterface $value): static;

    /**
     * Set billing address
     *
     * @param array $value
     * @return $this
     */
    public function setBillingAddress(array $value): static;

    /**
     * Set shipping address
     *
     * @param array $value
     * @return $this
     */
    public function setShippingAddress(array $value): static;

    /**
     * Set payment method
     *
     * @param MerchantPaymentPaymentMethodInterface $value
     * @return $this
     */
    public function setPaymentMethod(MerchantPaymentPaymentMethodInterface $value): static;
}
