<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Admin\Builder;

use Magento\Sales\Api\Data\OrderInterface;

/**
 * QliroOne Admin Order Item builder handler interface
 *
 * @api
 */
interface OrderItemHandlerInterface
{
    /**
     * Handle specific order item types and append them to the QliroOne order items list.
     * Items are plain associative arrays keyed by Qliro API field names.
     *
     * @param array[] $orderItems
     * @param OrderInterface $order
     * @return array[]
     */
    public function handle(array $orderItems, OrderInterface $order): array;
}
