<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Admin Update Merchant Reference Request interface
 *
 * @api
 */
interface AdminUpdateMerchantReferenceRequestInterface
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
     * Get new merchant reference value
     *
     * @return string
     */
    public function getNewMerchantReference(): string;

    /**
     * Set merchant API key
     *
     * @param string $value
     * @return static
     */
    public function setMerchantApiKey(string $value): static;

    /**
     * Set Qliro order ID
     *
     * @param int $value
     * @return static
     */
    public function setOrderId(int $value): static;

    /**
     * Set request ID
     *
     * @param string $value
     * @return static
     */
    public function setRequestId(string $value): static;

    /**
     * Set a new merchant reference value
     *
     * @param string $value
     * @return static
     */
    public function setNewMerchantReference(string $value): static;
}
