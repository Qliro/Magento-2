<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

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
     * @return \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[]
     */
    public function getOrderItems(): array;

    /**
     * @return \Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface
     */
    public function getCustomer(): QliroOrderCustomerInterface;

    /**
     * @return \Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterface
     */
    public function getBillingAddress(): \Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterface;

    /**
     * @return \Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterface
     */
    public function getShippingAddress(): \Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterface;

    /**
     * @return \Qliro\QliroOne\Api\Data\MerchantPaymentPaymentMethodInterface
     */
    public function getPaymentMethod(): \Qliro\QliroOne\Api\Data\MerchantPaymentPaymentMethodInterface; 

    /**
     * @param string $value
     * @return self
     */
    public function setMerchantReference(string $value);

    /**
     * @param string $value
     * @return self
     */
    public function setMerchantApiKey(string $value);

    /**
     * @param string $value
     * @return self
     */
    public function setCountry(string $value);

    /**
     * @param string $value
     * @return self
     */
    public function setCurrency(string $value);

    /**
     * @param string $value
     * @return self
     */
    public function setLanguage(string $value);

    /**
     * @param \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[] $value
     * @return self
     */
    public function setOrderItems(array $value): self;

    /**
     * @param string $value
     * @return self
     */
    public function setMerchantOrderManagementStatusPushUrl(string $value): self;

    /**
     * @param \Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface $value
     * @return self
     */
    public function setCustomer(\Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface $value): self;

    /**
     * @param \Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterface $value
     * @return self
     */
    public function setBillingAddress(\Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterface $value): self;

    /**
     * @param \Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterface $value
     * @return self
     */
    public function setShippingAddress(\Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterface $value): self;

    /**
     * @param \Qliro\QliroOne\Api\Data\MerchantPaymentPaymentMethodInterface $paymentMethod
     * @return self
     */
    public function setPaymentMethod(
        \Qliro\QliroOne\Api\Data\MerchantPaymentPaymentMethodInterface $paymentMethod
    ): self;
}
