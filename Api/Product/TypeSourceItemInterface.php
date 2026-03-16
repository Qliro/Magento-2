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
 */
interface TypeSourceItemInterface
{
    /**
     * @return int
     */
    public function getId(): int;

    /**
     * @return string
     */
    public function getSku(): string;

    /**
     * @return string
     */
    public function getType(): string;

    /**
     * @return string
     */
    public function getName(): string;

    /**
     * @return Product
     */
    public function getProduct(): Product;

    /**
     * @return float
     */
    public function getQty(): float;

    /**
     * @return float
     */
    public function getPriceInclTax(): float;

    /**
     * @return float
     */
    public function getPriceExclTax(): float;

    /**
     * @return float
     */
    public function getVatRate(): float;

    /**
     * @return mixed
     */
    public function getItem(): mixed;

    /**
     * @return TypeSourceItemInterface|null
     */
    public function getParent(): ?TypeSourceItemInterface;

    /**
     * @param int $value
     * @return $this
     */
    public function setId(int $value): static;

    /**
     * @param string $value
     * @return $this
     */
    public function setSku(string $value): static;

    /**
     * @param string $value
     * @return $this
     */
    public function setType(string $value): static;

    /**
     * @param string $value
     * @return $this
     */
    public function setName(string $value): static;

    /**
     * @param Product $value
     * @return $this
     */
    public function setProduct(Product $value): static;

    /**
     * @param float $value
     * @return $this
     */
    public function setQty(float $value): static;

    /**
     * @param float $value
     * @return $this
     */
    public function setPriceInclTax(float $value): static;

    /**
     * @param float $value
     * @return $this
     */
    public function setPriceExclTax(float $value): static;

    /**
     * @param float $value
     * @return $this
     */
    public function setVatRate(float $value): static;

    /**
     * @param mixed $value
     * @return $this
     */
    public function setItem(mixed $value): static;

    /**
     * @param TypeSourceItemInterface $value
     * @return $this
     */
    public function setParent(TypeSourceItemInterface $value): static;

    /**
     * @param boolean $value
     * @return $this
     */
    public function setSubscription(bool $value): static;

    /**
     * @return bool
     */
    public function getSubscription(): bool;
}
