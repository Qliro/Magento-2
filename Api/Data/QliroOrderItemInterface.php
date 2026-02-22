<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * QliroOne Order Item interface
 *
 * Depending on the data provided when sending an order item, Qliro One will interpret the type and price according to the table below.
 *
 * +----------------------+-----------------+-----------------+----------------------+-------------------------+------------------------+
 * | Type                 | PriceIncVat     | PriceExVat      | Interpreted Type     | Interpreted PriceIncVat | Interpreted PriceExVat |
 * +----------------------+-----------------+-----------------+----------------------+-------------------------+------------------------+
 * | null                 | Positive (X>=0) | Positive (Y>=0) | Product              | X                       | Y                      |
 * | null                 | Negative (X<0)  | Negative (Y<0)  | Discount             | X                       | Y                      |
 * | Product/Fee/Shipping | X               | Y               | Product/Fee/Shipping | Abs(X)                  | Abs(Y)                 |
 * | Discount             | X               | Y               | Discount             | -Abs(X)                 | -Abs(Y)                |
 * +----------------------+-----------------+-----------------+----------------------+-------------------------+------------------------+
 *
 * @api
 */
interface QliroOrderItemInterface extends ContainerInterface
{
    const TYPE_PRODUCT = 'Product';
    const TYPE_DISCOUNT = 'Discount';
    const TYPE_FEE = 'Fee';
    const TYPE_SHIPPING = 'Shipping';
    const TYPE_BUNDLE = 'Bundle';

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
     * Additional metadata.
     *
     * In OrderManagement API can be used to have two possible elements
     * - HeaderLines (array) Array of strings that will be displayed above the item on the invoice.
     *   Maximum number of strings is 5 and maximum length of each string is 115 characters.
     * - FooterLines (array) Array of strings that will be displayed below the item on the invoice.
     *   Maximum number of strings is 5 and maximum length of each string is 115 characters.
     *
     * @param array|null $value
     * @return $this
     */
    public function setMetadata(?array $value): static;
}
