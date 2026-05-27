<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin;

use Qliro\QliroOne\Api\Data\AdminUpdateItemsRequestInterface;

/**
 * Update QliroOne order items request class
 */
class UpdateItemsRequest implements AdminUpdateItemsRequestInterface
{
    private string $merchantApiKey = '';
    private int $orderId = 0;
    private string $currency = '';
    private array $orderItems = [];
    private string $requestId = '';

    public function getMerchantApiKey(): string
    {
        return $this->merchantApiKey;
    }

    public function setMerchantApiKey($merchantApiKey): static
    {
        $this->merchantApiKey = (string)$merchantApiKey;
        return $this;
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function setOrderId($orderId): static
    {
        $this->orderId = (int)$orderId;
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

    public function getOrderItems(): array
    {
        return $this->orderItems;
    }

    public function setOrderItems($orderItems): static
    {
        $this->orderItems = $orderItems;
        return $this;
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function setRequestId($requestId): static
    {
        $this->requestId = (string)$requestId;
        return $this;
    }
}
