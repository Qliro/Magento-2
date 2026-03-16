<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Client;

/**
 * Merchant API client interface
 *
 * @api
 */
interface MerchantInterface
{
    /**
     * Perform QliroOne order creation
     *
     * @param array $payload
     * @return int|string|null
     */
    public function createOrder(array $payload): int|string|null;

    /**
     * Get QliroOne order by its Qliro Order ID.
     *
     * @param int $qliroOrderId
     * @return array
     */
    public function getOrder(int $qliroOrderId): array;

    /**
     * Update QliroOne order.
     *
     * @param int $qliroOrderId
     * @param array $payload
     * @return int|string
     */
    public function updateOrder(int $qliroOrderId, array $payload): int|string;
}
