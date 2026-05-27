<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder;

use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;

/**
 * Base Qliro order item model.
 *
 * Provides a concrete implementation of QliroOrderItemInterface that can be
 * extended by specialised item classes (e.g. OrderItemAction).
 */
class Item implements QliroOrderItemInterface
{
    private string $merchantReference = '';
    private string $type = '';
    private float $quantity = 0.0;
    private float $pricePerItemIncVat = 0.0;
    private float $pricePerItemExVat = 0.0;
    private float $vatRate = 0.0;
    private string $description = '';
    private array $metadata = [];

    public function getMerchantReference(): string
    {
        return $this->merchantReference;
    }

    public function setMerchantReference(string $value): static
    {
        $this->merchantReference = $value;
        return $this;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $value): static
    {
        $this->type = $value;
        return $this;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function setQuantity(float $value): static
    {
        $this->quantity = $value;
        return $this;
    }

    public function getPricePerItemIncVat(): float
    {
        return $this->pricePerItemIncVat;
    }

    public function setPricePerItemIncVat(float $value): static
    {
        $this->pricePerItemIncVat = $value;
        return $this;
    }

    public function getPricePerItemExVat(): float
    {
        return $this->pricePerItemExVat;
    }

    public function setPricePerItemExVat(float $value): static
    {
        $this->pricePerItemExVat = $value;
        return $this;
    }

    public function getVatRate(): float
    {
        return $this->vatRate;
    }

    public function setVatRate(float $value): static
    {
        $this->vatRate = $value;
        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $value): static
    {
        $this->description = $value;
        return $this;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function setMetadata(array $value): static
    {
        $this->metadata = $value;
        return $this;
    }
}
