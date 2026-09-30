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
     * @inheritDoc
     */
    public function getId(): int
    {
        return $this->id;
    }

    /**
     * @inheritDoc
     */
    public function setId(int $value): static
    {
        $this->id = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getSku(): string
    {
        return $this->sku;
    }

    /**
     * @inheritDoc
     */
    public function setSku(string $value): static
    {
        $this->sku = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getType(): string
    {
        return $this->type;
    }

    /**
     * @inheritDoc
     */
    public function setType(string $value): static
    {
        $this->type = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @inheritDoc
     */
    public function setName(string $value): static
    {
        $this->name = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getProduct(): Product
    {
        return $this->product;
    }

    /**
     * @inheritDoc
     */
    public function setProduct(Product $value): static
    {
        $this->product = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getQty(): float
    {
        return $this->qty;
    }

    /**
     * @inheritDoc
     */
    public function setQty(float $value): static
    {
        $this->qty = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getPriceInclTax(): float
    {
        return $this->priceInclTax;
    }

    /**
     * @inheritDoc
     */
    public function setPriceInclTax(float $value): static
    {
        $this->priceInclTax = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getPriceExclTax(): float
    {
        return $this->priceExclTax;
    }

    /**
     * @inheritDoc
     */
    public function setPriceExclTax(float $value): static
    {
        $this->priceExclTax = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getVatRate(): float
    {
        return $this->vatRate;
    }

    /**
     * @inheritDoc
     */
    public function setVatRate(float $value): static
    {
        $this->vatRate = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getItem(): mixed
    {
        return $this->item;
    }

    /**
     * @inheritDoc
     */
    public function setItem(mixed $value): static
    {
        $this->item = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getParent(): ?TypeSourceItemInterface
    {
        return $this->parent;
    }

    /**
     * @inheritDoc
     */
    public function setParent(TypeSourceItemInterface $value): static
    {
        $this->parent = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getSubscription(): bool
    {
        return $this->subscription;
    }

    /**
     * @inheritDoc
     */
    public function setSubscription(bool $value): static
    {
        $this->subscription = $value;

        return $this;
    }
}
