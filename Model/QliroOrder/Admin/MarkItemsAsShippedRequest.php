<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin;

use Qliro\QliroOne\Api\Data\AdminMarkItemsAsShippedRequestInterface;

/**
 * Mark Items As Shipped Request class
 */
class MarkItemsAsShippedRequest implements AdminMarkItemsAsShippedRequestInterface
{
    private string $merchantApiKey = '';
    private int $orderId = 0;
    private string $currency = '';
    private array $shipments = [];
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

    public function getShipments(): array
    {
        return $this->shipments;
    }

    public function setShipments(array $value): static
    {
        $this->shipments = $value;
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
