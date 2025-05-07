<?php

/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Api\Product;

use Magento\Quote\Api\Data\CartItemInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

interface ProductNameResolverInterface
{
    /**
     * Resolve product name
     *
     * @param OrderItemInterface|CartItemInterface $item
     * @return string
     */
    public function getName(OrderItemInterface|CartItemInterface $item): string;
}
