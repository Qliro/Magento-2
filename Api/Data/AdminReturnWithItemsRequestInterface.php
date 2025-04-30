<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Api\Data;

/**
 * Interface AdminReturnWithItemsRequestInterface
 *
 * Represents a contract for handling return requests with associated items in an administrative context.
 */
interface AdminReturnWithItemsRequestInterface extends ContainerInterface
{
    /**
     * Retrieves the API key associated with the merchant.
     *
     * @return string
     */
    public function getMerchantApiKey();

    /**
     * Retrieves the payment reference.
     *
     * @return int
     */
    public function getPaymentReference();

    /**
     * Retrieves the unique identifier for the request.
     *
     * @return string The request ID, type may vary based on implementation.
     */
    public function getRequestId();

    /**
     * Retrieves the currency.
     *
     * @return string The currency value. The return type depends on the implementation.
     */
    public function getCurrency();

    /**
     * Retrieves the items associated with an order.
     *
     * @return \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[]
     */
    public function getOrderItems();

    /**
     * Retrieves the fees associated with a specific operation or transaction.
     *
     * @return \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[] The fees value, which could be of various data types depending on implementation.
     */
    public function getFees();

    /**
     * Sets the API key for the merchant.
     *
     * @param string $value The API key to be set.
     * @return $this
     */
    public function setMerchantApiKey($value);

    /**
     * Sets the payment reference value.
     *
     * @param int $value The value to set as the payment reference.
     * @return $this
     */
    public function setPaymentReference($value);

    /**
     * Sets the request ID.
     *
     * @param string $value The value to set as the request ID.
     * @return $this
     */
    public function setRequestId($value);

    /**
     * Sets the currency.
     *
     * @param string $value The currency code to set.
     * @return $this
     */
    public function setCurrency($value);

    /**
     * Sets the order items for an order.
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[] $orderItems The items to associate with the order.
     * @return $this
     */
    public function setOrderItems($orderItems);

    /**
     * Sets the fees.
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[] $value The value to set as fees.
     * @return $this
     */
    public function setFees($value);

    /**
     * Sets the order ID.
     *
     * @param int $value The order ID to set.
     * @return $this
     */
    public function setOrderId(int $value);

    /**
     * Retrieves the unique identifier of the order.
     *
     * @return int The unique order ID.
     */
    public function getOrderId(): int;

    /**
     * Sets the returns value.
     *
     * @param array $value The array value to set.
     * @return $this
     */
    public function setReturns(array $value);

    /**
     * Retrieves the list of returns.
     *
     * @return array The list of returned items.
     */
    public function getReturns(): array;

    /**
     * Sets the payment transaction ID.
     *
     * @param int $value The payment transaction ID to set.
     * @return $this
     */
    public function setPaymentTransactionId(int$value);

    /**
     * Retrieves the unique identifier of the payment transaction.
     *
     * @return int The unique transaction ID.
     */
    public function getPaymentTransactionId(): int;

    /**
     * Retrieves a list of available discounts.
     *
     * @return \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[] An array of available discounts
     */
    public function getDiscounts();

    /**
     * Sets the discounts for the item or order.
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[] $value The discounts to be applied. This could be a single discount or a collection of discounts.
     * @return $this
     */
    public function setDiscounts($value);
}
