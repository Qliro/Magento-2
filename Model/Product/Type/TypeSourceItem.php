<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Product\Type;

use Magento\Catalog\Model\Product;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterface;

/**
 * Type Source Item class.
 * Used for mapping Quote, Invoice and other items.
 */
class TypeSourceItem implements TypeSourceItemInterface
{
    /**
     * @var int
     */
    private int $id;

    /**
     * @var string
     */
    private string $sku;

    /**
     * @var string
     */
    private string $type;

    /**
     * @var string
     */
    private string $name;

    /**
     * @var Product
     */
    private Product $product;

    /**
     * @var float
     */
    private float $qty;

    /**
     * @var float
     */
    private float $priceInclTax;

    /**
     * @var float
     */
    private float $priceExclTax;

    /**
     * @var float
     */
    private float $vatRate;

    /**
     * @var mixed
     */
    private mixed $item;

    /**
     * @var TypeSourceItemInterface|null
     */
    private ?TypeSourceItemInterface $parent = null;

    /**
     * @var boolean
     */
    private bool $subscription = false;

    /**
     * @inheirtDoc
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @inheirtDoc
     */
    public function setId(int $value): static
    {
        $this->id = $value;

        return $this;
    }

    /**
     * @inheirtDoc
     */
    public function getSku(): string
    {
        return $this->sku;
    }

    /**
     * @inheirtDoc
     */
    public function setSku(string $value): static
    {
        $this->sku = $value;

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
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @inheirtDoc
     */
    public function setName(string $value): static
    {
        $this->name = $value;

        return $this;
    }

    /**
     * @inheirtDoc
     */
    public function getProduct(): Product
    {
        return $this->product;
    }

    /**
     * @inheirtDoc
     */
    public function setProduct(Product $value): static
    {
        $this->product = $value;

        return $this;
    }

    /**
     * @inheirtDoc
     */
    public function getQty(): float
    {
        return $this->qty;
    }

    /**
     * @inheirtDoc
     */
    public function setQty(float $value): static
    {
        $this->qty = $value;

        return $this;
    }

    /**
     * @inheirtDoc
     */
    public function getPriceInclTax(): float
    {
        return $this->priceInclTax;
    }

    /**
     * @inheirtDoc
     */
    public function setPriceInclTax(float $value): static
    {
        $this->priceInclTax = $value;

        return $this;
    }

    /**
     * @inheirtDoc
     */
    public function getPriceExclTax(): float
    {
        return $this->priceExclTax;
    }

    /**
     * @inheirtDoc
     */
    public function setPriceExclTax(float $value): static
    {
        $this->priceExclTax = $value;

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
    public function getItem(): mixed
    {
        return $this->item;
    }

    /**
     * @inheirtDoc
     */
    public function setItem(mixed $value): static
    {
        $this->item = $value;

        return $this;
    }

    /**
     * @inheirtDoc
     */
    public function getParent(): ?TypeSourceItemInterface
    {
        return $this->parent;
    }

    /**
     * @inheirtDoc
     */
    public function setParent(TypeSourceItemInterface $value): static
    {
        $this->parent = $value;

        return $this;
    }

    /**
     * @inheirtDoc
     */
    public function getSubscription(): bool
    {
        return $this->subscription;
    }

    /**
     * @inheirtDoc
     */
    public function setSubscription(bool $value): static
    {
        $this->subscription = $value;

        return $this;
    }
}
