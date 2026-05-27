<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin;

use Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface;

/**
 * Admin QliroOne Transaction Response class
 */
class TransactionResponse implements AdminTransactionResponseInterface
{
    private ?int $paymentTransactionId = null;
    private ?string $status = null;
    private ?string $type = null;
    private ?int $reversalPaymentTransactionId = null;
    private ?string $reversalPaymentTransactionStatus = null;

    public function getPaymentTransactionId(): ?int
    {
        return $this->paymentTransactionId;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function getReversalPaymentTransactionId(): ?int
    {
        return $this->reversalPaymentTransactionId;
    }

    public function getReversalPaymentTransactionStatus(): ?string
    {
        return $this->reversalPaymentTransactionStatus;
    }

    public function setPaymentTransactionId(mixed $value): static
    {
        $this->paymentTransactionId = $value !== null ? (int)$value : null;
        return $this;
    }

    public function setStatus(mixed $value): static
    {
        $this->status = $value !== null ? (string)$value : null;
        return $this;
    }

    public function setType(mixed $value): static
    {
        $this->type = $value !== null ? (string)$value : null;
        return $this;
    }

    public function setReversalPaymentTransactionId(mixed $value): static
    {
        $this->reversalPaymentTransactionId = $value !== null ? (int)$value : null;
        return $this;
    }

    public function setReversalPaymentTransactionStatus(mixed $value): static
    {
        $this->reversalPaymentTransactionStatus = $value !== null ? (string)$value : null;
        return $this;
    }
}
