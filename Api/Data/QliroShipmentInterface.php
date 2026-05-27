<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * QliroOne Shipment interface — used in MarkItemsAsShipped requests
 *
 * @api
 */
interface QliroShipmentInterface
{
    /**
     * Get the payment transaction ID this shipment captures against, or null for a new capture
     *
     * @return int|null
     */
    public function getPaymentTransactionId(): ?int;

    /**
     * Get shipment order items
     *
     * @return QliroOrderItemInterface[]
     */
    public function getOrderItems(): array;

    /**
     * Set the payment transaction ID this shipment captures against
     *
     * @param int $value
     * @return static
     */
    public function setPaymentTransactionId(int $value): static;

    /**
     * Set shipment order items
     *
     * @param QliroOrderItemInterface[] $value
     * @return static
     */
    public function setOrderItems(array $value): static;
}
