<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Admin Mark Items As Shipped Request interface
 *
 * @api
 */
interface AdminMarkItemsAsShippedRequestInterface
{
    /**
     * Get merchant API key
     *
     * @return string
     */
    public function getMerchantApiKey(): string;

    /**
     * Get Qliro order ID
     *
     * @return int
     */
    public function getOrderId(): int;

    /**
     * Get currency code
     *
     * @return string
     */
    public function getCurrency(): string;

    /**
     * Get shipments
     *
     * @return QliroShipmentInterface[]
     */
    public function getShipments(): array;

    /**
     * Get request ID
     *
     * @return string
     */
    public function getRequestId(): string;

    /**
     * Set the merchant API key
     *
     * @param string $value
     * @return $this
     */
    public function setMerchantApiKey(string $value): static;

    /**
     * Set Qliro order ID
     *
     * @param int $value
     * @return $this
     */
    public function setOrderId(int $value): static;

    /**
     * Set currency code
     *
     * @param string $value
     * @return $this
     */
    public function setCurrency(string $value): static;

    /**
     * Set shipments
     *
     * @param QliroShipmentInterface[] $value
     * @return $this
     */
    public function setShipments(array $value): static;

    /**
     * Set request ID
     *
     * @param string $value
     * @return $this
     */
    public function setRequestId(string $value): static;
}
