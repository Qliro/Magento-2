<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Api\Data;

/**
 * Interface representing a request to add items to an invoice in the admin context.
 */
interface AdminAddItemsToInvoiceRequestInterface extends ContainerInterface
{
    /**
     * Retrieves the request identifier associated with the current instance.
     *
     * @return string The request identifier as a string.
     */
    public function getRequestId(): string;

    /**
     * Sets the request ID for the current instance.
     *
     * @param string $value The request ID to set.
     * @return self Returns the current instance for method chaining.
     */
    public function setRequestId(string $value): self;

    /**
     * Retrieves the API key associated with the merchant.
     *
     * @return string The merchant's API key as a string.
     */
    public function getMerchantApiKey(): string;

    /**
     * Sets the merchant API key for the current instance.
     *
     * @param string $value The API key to be set.
     * @return self Returns the current instance for method chaining.
     */
    public function setMerchantApiKey(string $value): self;

    /**
     * Retrieves the order ID associated with the current instance.
     *
     * @return int The order ID as an integer.
     */
    public function getOrderId(): int;

    /**
     * Sets the order ID for the current instance.
     *
     * @param int $value The ID of the order to be set.
     * @return self Returns the current instance for method chaining.
     */
    public function setOrderId(int $value): self;

    /**
     * Retrieves the currency associated with the current instance.
     *
     * @return string The currency as a string.
     */
    public function getCurrency(): string;

    /**
     * Sets the currency value.
     *
     * @param string $value The currency to be set.
     * @return self Returns the current instance.
     */
    public function setCurrency(string $value): self;

    /**
     * Retrieves the list of additions associated with the current instance.
     *
     * @return AdminAdditionsInterface[] An array containing the additions.
     */
    public function getAdditions(): array;

    /**
     * Sets the additions for the current instance.
     *
     * @param AdminAdditionsInterface[] $value The list of additions to be set.
     * @return self The current instance for method chaining.
     */
    public function setAdditions(array $value): self;
}
