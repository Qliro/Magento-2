<?php declare(strict_types=1);
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\MerchantPayment;

use Qliro\QliroOne\Api\Data\AdminCreateMerchantPaymentRequestInterface;
use Qliro\QliroOne\Api\Data\MerchantPaymentCustomerInterface;
use Qliro\QliroOne\Api\Data\MerchantPaymentPaymentMethodInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;

/**
 * QliroOne Merchant Payment Create Request concrete implementation
 */
class CreateRequest implements AdminCreateMerchantPaymentRequestInterface
{
    /**
     * @var string
     */
    private string $requestId = '';

    /**
     * @var string
     */
    private string $merchantReference = '';

    /**
     * @var string
     */
    private string $merchantApiKey = '';

    /**
     * @var string
     */
    private string $country = '';

    /**
     * @var string
     */
    private string $currency = '';

    /**
     * @var string
     */
    private string $language = '';

    /**
     * @var QliroOrderItemInterface[]
     */
    private array $orderItems = [];

    /**
     * @var string
     */
    private string $merchantOrderManagementStatusPushUrl = '';

    /**
     * @var MerchantPaymentCustomerInterface|null
     */
    private ?MerchantPaymentCustomerInterface $customer = null;

    /**
     * @var array|null
     */
    private ?array $billingAddress = null;

    /**
     * @var array|null
     */
    private ?array $shippingAddress = null;

    /**
     * @var MerchantPaymentPaymentMethodInterface|null
     */
    private ?MerchantPaymentPaymentMethodInterface $paymentMethod = null;

    /**
     * @inheritDoc
     */
    public function getRequestId(): string
    {
        return $this->requestId;
    }

    /**
     * @return string
     */
    public function getMerchantReference(): string
    {
        return $this->merchantReference;
    }

    /**
     * @return string
     */
    public function getMerchantApiKey(): string
    {
        return $this->merchantApiKey;
    }

    /**
     * @return string
     */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * @return string
     */
    public function getCountry(): string
    {
        return $this->country;
    }

    /**
     * @return string
     */
    public function getLanguage(): string
    {
        return $this->language;
    }

    /**
     * @return string
     */
    public function getMerchantOrderManagementStatusPushUrl(): string
    {
        return $this->merchantOrderManagementStatusPushUrl;
    }

    /**
     * @inheritdoc
     */
    public function getOrderItems(): array
    {
        return $this->orderItems;
    }

    /**
     * @inheritdoc
     */
    public function getCustomer(): ?MerchantPaymentCustomerInterface
    {
        return $this->customer;
    }

    /**
     * @inheritdoc
     */
    public function getBillingAddress(): ?array
    {
        return $this->billingAddress;
    }

    /**
     * @inheritdoc
     */
    public function getShippingAddress(): ?array
    {
        return $this->shippingAddress;
    }

    /**
     * @inheritdoc
     */
    public function getPaymentMethod(): ?MerchantPaymentPaymentMethodInterface
    {
        return $this->paymentMethod;
    }

    /**
     * @inheritDoc
     */
    public function setRequestId(string $value): static
    {
        $this->requestId = $value;
        return $this;
    }

    /**
     * @param string $value
     * @return static
     */
    public function setMerchantReference(string $value): static
    {
        $this->merchantReference = $value;

        return $this;
    }

    /**
     * @param string $value
     * @return static
     */
    public function setMerchantApiKey(string $value): static
    {
        $this->merchantApiKey = $value;

        return $this;
    }

    /**
     * @param string $value
     * @return static
     */
    public function setCurrency(string $value): static
    {
        $this->currency = $value;

        return $this;
    }

    /**
     * @param string $value
     * @return static
     */
    public function setCountry(string $value): static
    {
        $this->country = $value;

        return $this;
    }

    /**
     * @param string $value
     * @return static
     */
    public function setLanguage(string $value): static
    {
        $this->language = $value;

        return $this;
    }

    /**
     * @param QliroOrderItemInterface[] $value
     * @return static
     */
    public function setOrderItems(array $value): static
    {
        $this->orderItems = $value;

        return $this;
    }

    /**
     * @param string $value
     * @return static
     */
    public function setMerchantOrderManagementStatusPushUrl(string $value): static
    {
        $this->merchantOrderManagementStatusPushUrl = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setCustomer(MerchantPaymentCustomerInterface $value): static
    {
        $this->customer = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setBillingAddress(array $value): static
    {
        $this->billingAddress = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setShippingAddress(array $value): static
    {
        $this->shippingAddress = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setPaymentMethod(MerchantPaymentPaymentMethodInterface $value): static
    {
        $this->paymentMethod = $value;
        return $this;
    }
}
