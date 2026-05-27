<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin;

use Qliro\QliroOne\Api\Data\AdminOrderInterface;
use Qliro\QliroOne\Api\Data\AdminOrderItemActionInterface;
use Qliro\QliroOne\Api\Data\AdminOrderPaymentTransactionInterface;

/**
 * Admin QliroOne Order class
 */
class AdminOrder implements AdminOrderInterface
{
    private int $orderId = 0;
    private string $merchantReference = '';
    private float $totalPrice = 0.0;
    private string $country = '';
    private string $currency = '';
    private string $language = '';
    private bool $signupForNewsletter = false;
    private mixed $identityVerification = null;
    private mixed $customer = null;
    private mixed $billingAddress = null;
    private mixed $shippingAddress = null;
    private array $orderItemActions = [];
    private array $paymentTransactions = [];

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function setOrderId($orderId): static
    {
        $this->orderId = (int)$orderId;
        return $this;
    }

    public function getMerchantReference(): string
    {
        return $this->merchantReference;
    }

    public function setMerchantReference($merchantReference): static
    {
        $this->merchantReference = (string)$merchantReference;
        return $this;
    }

    public function getTotalPrice(): float
    {
        return $this->totalPrice;
    }

    public function setTotalPrice($totalPrice): static
    {
        $this->totalPrice = (float)$totalPrice;
        return $this;
    }

    public function getCountry(): string
    {
        return $this->country;
    }

    public function setCountry($country): static
    {
        $this->country = (string)$country;
        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency($currency): static
    {
        $this->currency = (string)$currency;
        return $this;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function setLanguage($language): static
    {
        $this->language = (string)$language;
        return $this;
    }

    public function getSignupForNewsletter(): bool
    {
        return $this->signupForNewsletter;
    }

    public function setSignupForNewsletter($signupForNewsletter): static
    {
        $this->signupForNewsletter = (bool)$signupForNewsletter;
        return $this;
    }

    public function getIdentityVerification(): mixed
    {
        return $this->identityVerification;
    }

    public function setIdentityVerification($identityVerification): static
    {
        $this->identityVerification = $identityVerification;
        return $this;
    }

    public function getCustomer(): mixed
    {
        return $this->customer;
    }

    public function setCustomer($customer): static
    {
        $this->customer = $customer;
        return $this;
    }

    public function getBillingAddress(): mixed
    {
        return $this->billingAddress;
    }

    public function setBillingAddress($billingAddress): static
    {
        $this->billingAddress = $billingAddress;
        return $this;
    }

    public function getShippingAddress(): mixed
    {
        return $this->shippingAddress;
    }

    public function setShippingAddress($shippingAddress): static
    {
        $this->shippingAddress = $shippingAddress;
        return $this;
    }

    /** @return AdminOrderItemActionInterface[] */
    public function getOrderItemActions(): array
    {
        return $this->orderItemActions;
    }

    public function setOrderItemActions($orderItemActions): static
    {
        $this->orderItemActions = $orderItemActions;
        return $this;
    }

    /** @return AdminOrderPaymentTransactionInterface[] */
    public function getPaymentTransactions(): array
    {
        return $this->paymentTransactions;
    }

    public function setPaymentTransactions($paymentTransactions): static
    {
        $this->paymentTransactions = $paymentTransactions;
        return $this;
    }
}
