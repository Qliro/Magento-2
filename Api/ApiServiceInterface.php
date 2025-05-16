<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Api;

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
     * @param int|null $storeId
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function get($endpoint, $data = [], $storeId = null);

    /**
     * Perform POST request
     *
     * @param string $endpoint
     * @param array $data
     * @param int|null $storeId
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function post($endpoint, $data = [], $storeId = null);

    /**
     * Perform PUT request
     *
     * @param string $endpoint
     * @param array $data
     * @param int|null $storeId
     * @return array
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function put($endpoint, $data = [], $storeId = null);
}
