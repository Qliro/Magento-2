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
     * @return int|null
     */
    public function createOrder(array $payload): ?int;

    /**
     * Get QliroOne order by its Qliro Order ID.
     *
     * @param int $qliroOrderId
     * @return array
     */
    public function getOrder(int $qliroOrderId): array;

    /**
     * Get a QliroOne order by its merchant reference.
     *
     * Used to recover an order whose id was lost (e.g. a createOrder request that timed out
     * after Qliro had already created the order), instead of creating a duplicate.
     *
     * @param string $merchantReference
     * @return array Empty array when no order exists for the reference.
     */
    public function getOrderByMerchantReference(string $merchantReference): array;

    /**
     * Update QliroOne order.
     *
     * @param int $qliroOrderId
     * @param array $payload
     * @return int
     */
    public function updateOrder(int $qliroOrderId, array $payload): int;
}
