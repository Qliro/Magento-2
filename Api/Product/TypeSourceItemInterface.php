<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Product;

use Magento\Catalog\Model\Product;

/**
 * Type Source Item interface
 *
 * @api
 */
interface TypeSourceItemInterface
{
    /**
     * Get item ID
     *
     * @return int|string
     */
    public function getId(): int|string;

    /**
     * Get item SKU
     *
     * @return string
     */
    public function getSku(): string;

    /**
     * Get product type
     *
     * @return string
     */
    public function getType(): string;

    /**
     * Get item display name
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get the associated product model
     *
     * @return Product
     */
    public function getProduct(): Product;

    /**
     * Get item quantity
     *
     * @return float
     */
    public function getQty(): float;

    /**
     * Get unit price including tax
     *
     * @return float
     */
    public function getPriceInclTax(): float;

    /**
     * Get unit price excluding tax
     *
     * @return float
     */
    public function getPriceExclTax(): float;

    /**
     * Get VAT rate
     *
     * @return float
     */
    public function getVatRate(): float;

    /**
     * Get the raw quote or order item
     *
     * @return mixed
     */
    public function getItem(): mixed;

    /**
     * Get the parent source item, or null for top-level items
     *
     * @return TypeSourceItemInterface|null
     */
    public function getParent(): ?TypeSourceItemInterface;

    /**
     * Get whether this item is a subscription product
     *
     * @return bool
     */
    public function getSubscription(): bool;

    /**
     * Set item ID
     *
     * @param int|string $value
     * @return static
     */
    public function setId(int|string $value): static;

    /**
     * Set item SKU
     *
     * @param string $value
     * @return static
     */
    public function setSku(string $value): static;

    /**
     * Set product type
     *
     * @param string $value
     * @return static
     */
    public function setType(string $value): static;

    /**
     * Set item display name
     *
     * @param string $value
     * @return static
     */
    public function setName(string $value): static;

    /**
     * Set the associated product model
     *
     * @param Product $value
     * @return static
     */
    public function setProduct(Product $value): static;

    /**
     * Set item quantity
     *
     * @param float $value
     * @return static
     */
    public function setQty(float $value): static;

    /**
     * Set unit price including tax
     *
     * @param float $value
     * @return static
     */
    public function setPriceInclTax(float $value): static;

    /**
     * Set unit price excluding tax
     *
     * @param float $value
     * @return static
     */
    public function setPriceExclTax(float $value): static;

    /**
     * Set VAT rate
     *
     * @param float $value
     * @return static
     */
    public function setVatRate(float $value): static;

    /**
     * Set the raw quote or order item
     *
     * @param mixed $value
     * @return static
     */
    public function setItem(mixed $value): static;

    /**
     * Set the parent source item
     *
     * @param TypeSourceItemInterface $value
     * @return static
     */
    public function setParent(TypeSourceItemInterface $value): static;

    /**
     * Set whether this item is a subscription product
     *
     * @param bool $value
     * @return static
     */
    public function setSubscription(bool $value): static;
}
