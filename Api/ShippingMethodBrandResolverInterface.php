<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api;

use Magento\Quote\Model\Quote\Address\Rate;

/**
 * Shipping Method Brand Resolver interface
 *
 * @api
 */
interface ShippingMethodBrandResolverInterface
{
    /**
     * Resolve the brand name for a shipping method
     *
     * Supported logotypes: Aramex, Best, Bring, Budbee, DHL, Instabox, MTD, Posti, PostNord, Schenker, UPS.
     *
     * @param Rate $shippingRate
     * @return string
     */
    public function resolve(Rate $shippingRate): string;
}
