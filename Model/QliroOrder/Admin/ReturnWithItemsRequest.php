<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin;

use Qliro\QliroOne\Api\Data\AdminReturnWithItemsRequestInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;

/**
 * Return With Item Request class
 *
 * All order item, fee, and discount collections are stored as plain arrays
 * (key => value maps matching the Qliro API field names), so no converter
 * is needed when building the returns' payload.
 */
class ReturnWithItemsRequest implements AdminReturnWithItemsRequestInterface
{
    private string $merchantApiKey = '';
    private int $paymentReference = 0;
    private string $requestId = '';
    private string $currency = '';
    private array $orderItems = [];
    private array $fees = [];
    private array $discounts = [];
    private int $orderId = 0;
    private int $paymentTransactionId = 0;
    private array $returns = [];

    /**
     * @inheritDoc
     */
    public function getMerchantApiKey(): string
    {
        return $this->merchantApiKey;
    }

    /**
     * @inheritDoc
     */
    public function setMerchantApiKey($value): static
    {
        $this->merchantApiKey = (string)$value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getPaymentReference(): int
    {
        return $this->paymentReference;
    }

    /**
     * @inheritDoc
     */
    public function setPaymentReference($value): static
    {
        $this->paymentReference = (int)$value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getRequestId(): string
    {
        return $this->requestId;
    }

    /**
     * @inheritDoc
     */
    public function setRequestId($value): static
    {
        $this->requestId = (string)$value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * @inheritDoc
     */
    public function setCurrency($value): static
    {
        $this->currency = (string)$value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getOrderItems(): array
    {
        return $this->orderItems;
    }

    /**
     * @inheritDoc
     */
    public function setOrderItems($orderItems): static
    {
        if (!count($orderItems)) {
            return $this;
        }

        foreach ($orderItems as $key => $orderItem) {
            if (($orderItem['Type'] ?? null) === QliroOrderItemInterface::TYPE_DISCOUNT) {
                $orderItems[$key]['PricePerItemExVat'] = -abs($orderItem['PricePerItemExVat'] ?? 0.0);
                $orderItems[$key]['PricePerItemIncVat'] = -abs($orderItem['PricePerItemIncVat'] ?? 0.0);
            }
        }

        $this->orderItems = $orderItems;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getFees(): array
    {
        return $this->fees;
    }

    /**
     * @inheritDoc
     */
    public function setFees($value): static
    {
        $this->fees = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setOrderId(int $value): static
    {
        $this->orderId = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getOrderId(): int
    {
        return $this->orderId;
    }

    /**
     * @inheritDoc
     */
    public function setReturns(array $value): static
    {
        $this->returns = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getReturns(): array
    {
        if ($this->paymentTransactionId) {
            $this->returns['PaymentTransactionId'] = $this->paymentTransactionId;
        }

        $orderItems = array_values(array_filter($this->orderItems));
        if ($orderItems) {
            $this->returns['OrderItems'] = $orderItems;
        }

        $fees = array_values(array_filter($this->fees));
        if ($fees) {
            $this->returns['Fees'] = $fees;
        }

        $discounts = array_values(array_filter($this->discounts));
        if ($discounts) {
            $this->returns['Discounts'] = $discounts;
        }

        return $this->returns;
    }

    /**
     * @inheritDoc
     */
    public function setPaymentTransactionId(int $value): static
    {
        $this->paymentTransactionId = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getPaymentTransactionId(): int
    {
        return $this->paymentTransactionId;
    }

    /**
     * @inheritDoc
     */
    public function getDiscounts(): array
    {
        return $this->discounts;
    }

    /**
     * @inheritDoc
     */
    public function setDiscounts($value): static
    {
        $this->discounts = $value;

        return $this;
    }
}
