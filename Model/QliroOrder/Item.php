<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder;

use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;

/**
 * QliroOne order item class
 */
class Item implements QliroOrderItemInterface
{
    /**
     * @var string
     */
    private string $merchantReference = '';

    /**
     * Get item type.
     * Can be 'Product', 'Discount', 'Fee' or 'Shipping'
     *
     * @var string
     */
    private string $type = '';

    /**
     * @var float
     */
    private float $quantity = 0.0;

    /**
     * @var float
     */
    private float $pricePerItemIncVat  = 0.0;

    /**
     * @var float
     */
    private float $pricePerItemExVat   = 0.0;

    /**
     * @var float
     */
    private float $vatRate = 0.0;

    /**
     * @var string
     */
    private string $description = '';

    /**
     * @var array
     */
    private array $metadata = [];


    /**
     * @inheirtDoc
     */
    public function getMerchantReference(): string
    {
        return $this->merchantReference;
    }

    /**
     * @inheirtDoc
     */
    public function setMerchantReference(string $value): static
    {
        $this->merchantReference = $value;

        return $this;
    }

    /**
     * @inheirtDoc
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @inheirtDoc
     */
    public function setType(string $value): static
    {
        $this->type = $value;

        return $this;
    }

    /**
     * @inheirtDoc
     */
    public function getQuantity(): float
    {
        return $this->quantity;
    }

    /**
     * @inheirtDoc
     */
    public function setQuantity(float $value): static
    {
        $this->quantity = $value;

        return $this;
    }

    /**
     * @inheirtDoc
     */
    public function getPricePerItemIncVat(): float
    {
        return $this->pricePerItemIncVat;
    }

    /**
     * @inheirtDoc
     */
    public function setPricePerItemIncVat(float $value): static
    {
        $this->pricePerItemIncVat = $value;

        return $this;
    }

    /**
     * @inheirtDoc
     */
    public function getPricePerItemExVat(): float
    {
        return $this->pricePerItemExVat;
    }

    /**
     * @inheirtDoc
     */
    public function setPricePerItemExVat(float $value): static
    {
        $this->pricePerItemExVat = $value;

        return $this;
    }

    /**
     * @inheirtDoc
     */
    public function getVatRate(): float
    {
        return $this->vatRate;
    }

    /**
     * @inheirtDoc
     */
    public function setVatRate(float $value): static
    {
        $this->vatRate = $value;

        return $this;
    }

    /**
     * @inheirtDoc
     */
    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @inheirtDoc
     */
    public function setDescription(string $value): static
    {
        $this->description = $value;

        return $this;
    }

    /**
     * @inheirtDoc
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * @inheirtDoc
     */
    public function setMetadata(array $value): static
    {
        if (is_null($value)) {
            return $this;
        }
        $this->metadata = $value;

        return $this;
    }
}
