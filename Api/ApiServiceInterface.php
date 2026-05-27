<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api;

use GuzzleHttp\Exception\GuzzleException;

/**
 * QliroOne Service Interface
 *
 * @api
 */
interface ApiServiceInterface
{
    /**
     * Perform GET request
     *
     * @param string $endpoint
     * @param array $data
     * @param int|string|null $storeId
     * @return array
     * @throws GuzzleException
     */
    public function get(string $endpoint, array $data = [], int|string|null $storeId = null): array;

    /**
     * Perform POST request
     *
     * @param string $endpoint
     * @param array $data
     * @param int|string|null $storeId
     * @return array
     * @throws GuzzleException
     */
    public function post(string $endpoint, array $data = [], int|string|null $storeId = null): array;

    /**
     * Perform a PUT request
     *
     * @param string $endpoint
     * @param array $data
     * @param int|string|null $storeId
     * @return array
     * @throws GuzzleException
     */
    public function put(string $endpoint, array $data = [], int|string|null $storeId = null): array;
}
