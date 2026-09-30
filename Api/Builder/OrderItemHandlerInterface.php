<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Builder;

/**
 * QliroOne Order Item builder handler interface
 */
interface OrderItemHandlerInterface
{
    /**
     * Handle specific type of order items and add them to the QliroOne order items list
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[] $orderItems
     * @param \Magento\Quote\Api\Data\CartInterface $quote
     * @return \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[]
     */
    public function handle($orderItems, $quote);
}
