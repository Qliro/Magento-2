<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Admin Cancel Order Request interface
 *
 * @api
 */
interface AdminCancelOrderRequestInterface
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
     * Get request ID
     *
     * @return string
     */
    public function getRequestId(): string;

    /**
     * Set merchant API key
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
     * Set request ID
     *
     * @param string $value
     * @return $this
     */
    public function setRequestId(string $value): static;
}
