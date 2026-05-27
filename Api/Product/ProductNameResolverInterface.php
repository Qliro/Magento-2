<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Product;

use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * Product Name Resolver interface
 *
 * @api
 */
interface ProductNameResolverInterface
{
    /**
     * Resolve the display name for a quote or order item
     *
     * @param OrderItemInterface|CartItemInterface $item
     * @return string
     */
    public function getName(OrderItemInterface|CartItemInterface $item): string;
}
