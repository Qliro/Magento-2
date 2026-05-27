<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Interface for a Merchant Payment Create Request data model
 *
 * @api
 */
interface MerchantPaymentCreateRequestInterface
{
    /**
     * @return string
     */
    public function getMerchantReference(): string;

    /**
     * @return string
     */
    public function getMerchantApiKey(): string;

    /**
     * @return string
     */
    public function getCurrency(): string;

    /**
     * @return string
     */
    public function getCountry(): string;

    /**
     * @return string
     */
    public function getLanguage(): string;

    /**
     * @return string
     */
    public function getMerchantOrderManagementStatusPushUrl(): string;

    /**
     * @return QliroOrderItemInterface[]
     */
    public function getOrderItems(): array;

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
     * @return MerchantPaymentPaymentMethodInterface
     */
    public function getPaymentMethod(): MerchantPaymentPaymentMethodInterface;

    /**
     * @param string $value
     * @return self
     */
    public function setMerchantReference(string $value): static;

    /**
     * @param string $value
     * @return self
     */
    public function setMerchantApiKey(string $value): static;

    /**
     * @param string $value
     * @return self
     */
    public function setCountry(string $value): static;

    /**
     * @param string $value
     * @return self
     */
    public function setCurrency(string $value): static;

    /**
     * @param string $value
     * @return self
     */
    public function setLanguage(string $value): static;

    /**
     * @param QliroOrderItemInterface[] $value
     * @return self
     */
    public function setOrderItems(array $value): static;

    /**
     * @param string $value
     * @return self
     */
    public function setMerchantOrderManagementStatusPushUrl(string $value): static;

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
     * @param MerchantPaymentPaymentMethodInterface $paymentMethod
     * @return self
     */
    public function setPaymentMethod(
        MerchantPaymentPaymentMethodInterface $paymentMethod
    ): static;
}
