<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * QliroOne Order Item interface
 * @api
 */
interface QliroOrderItemInterface
{
    const string TYPE_PRODUCT = 'Product';
    const string TYPE_DISCOUNT = 'Discount';
    const string TYPE_FEE = 'Fee';
    const string TYPE_SHIPPING = 'Shipping';
    const string TYPE_BUNDLE = 'Bundle';

    /**
     * @return string
     */
    public function getMerchantReference(): string;

    /**
     * Get item type.
     * Can be 'Product', 'Discount', 'Fee' or 'Shipping'
     *
     * @return string
     */
    public function getType(): string;

    /**
     * @return float
     */
    public function getQuantity(): float;

    /**
     * @return float
     */
    public function getPricePerItemIncVat(): float;

    /**
     * @return float
     */
    public function getPricePerItemExVat(): float;

    /**
     * @return float
     */
    public function getVatRate(): float;

    /**
     * @return string
     */
    public function getDescription(): string;

    /**
     * @return array
     */
    public function getMetadata(): array;

    /**
     * @param string $value
     * @return $this
     */
    public function setMerchantReference(string $value): static;

    /**
     * Set item type.
     * Can be 'Product', 'Discount', 'Fee' or 'Shipping'
     *
     * @param string $value
     * @return $this
     */
    public function setType(string $value): static;

    /**
     * @param float $value
     * @return $this
     */
    public function setQuantity(float $value): static;

    /**
     * @param float $value
     * @return $this
     */
    public function setPricePerItemIncVat(float $value): static;

    /**
     * @param float $value
     * @return $this
     */
    public function setPricePerItemExVat(float $value): static;

    /**
     * @param float $value
     * @return $this
     */
    public function setVatRate(float $value): static;

    /**
     * @param string $value
     * @return $this
     */
    public function setDescription(string $value): static;

    /**
     * Additional metadata
     *
     * @param array $value
     * @return $this
     */
    public function setMetadata(array $value): static;
}
