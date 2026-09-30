<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api;

/**
 * GeoIp Resolver interface
 */
interface GeoIpResolverInterface
{
    /**
     * Resolve country from IP address
     *
     * @param string $ipAddress
     * @return string|null
     */
    public function getCountryCode($ipAddress);
}
