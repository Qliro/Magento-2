<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api;

/**
 * GeoIp Resolver interface
 *
 * @api
 */
interface GeoIpResolverInterface
{
    /**
     * Resolve country code from an IP address
     *
     * @param string $ipAddress
     * @return string|null
     */
    public function getCountryCode(string $ipAddress): ?string;
}
