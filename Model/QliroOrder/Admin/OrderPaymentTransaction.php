<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin;

use Qliro\QliroOne\Api\Data\AdminOrderPaymentTransactionInterface;

/**
 * OrderPaymentTransaction class
 */
class OrderPaymentTransaction implements AdminOrderPaymentTransactionInterface
{
    private int $paymentTransactionId = 0;
    private string $type = '';
    private float $amount = 0.0;
    private string $currency = '';
    private string $status = '';
    private string $paymentMethodName = '';

    public function getPaymentTransactionId(): int
    {
        return $this->paymentTransactionId;
    }

    public function setPaymentTransactionId($paymentTransactionId): static
    {
        $this->paymentTransactionId = (int)$paymentTransactionId;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType($type): static
    {
        $this->type = (string)$type;
        return $this;
    }

    public function getAmount(): float
    {
        return $this->amount;
    }

    public function setAmount($amount): static
    {
        $this->amount = (float)$amount;
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

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus($status): static
    {
        $this->status = (string)$status;
        return $this;
    }

    public function getPaymentMethodName(): string
    {
        return $this->paymentMethodName;
    }

    public function setPaymentMethodName($paymentMethodName): static
    {
        $this->paymentMethodName = (string)$paymentMethodName;
        return $this;
    }
}
