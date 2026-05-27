<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Builder;

/**
 * QliroOne Quote Order Item builder handler interface
 *
 * @api
 */
interface OrderItemHandlerInterface
{
    /**
     * Handle specific order item types and append them to the QliroOne order items list
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[] $orderItems
     * @param \Magento\Quote\Api\Data\CartInterface $quote
     * @return \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[]
     */
    public function handle(array $orderItems, \Magento\Quote\Api\Data\CartInterface $quote): array;
}
