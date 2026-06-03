<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Api\Data;

/**
 * Interface AdminAdditionsInterface
 *
 * Defines a contract for managing additional admin-related functionalities,
 * including handling payment transaction identifiers and order items.
 */
interface AdminAdditionsInterface extends ContainerInterface
{
    /**
     * Retrieves the unique identifier of the payment transaction.
     *
     * @return int The transaction ID associated with the payment.
     */
    public function getPaymentTransactionId(): int;

    /**
     * Sets the unique identifier for the payment transaction.
     *
     * @param int $value The transaction ID to be associated with the payment.
     * @return self Returns the current instance for method chaining.
     */
    public function setPaymentTransactionId(int $value): self;

    /**
     * Retrieves a list of items associated with the order.
     *
     * @return QliroOrderItemInterface[] The array of order items.
     */
    public function getOrderItems(): array;

    /**
     * Sets the order items for the current order.
     *
     * @param QliroOrderItemInterface[] $value The list of order items to be associated with the order.
     * @return self The current instance for method chaining.
     */
    public function setOrderItems(array $value): self;
}
