<?php

/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Admin;

use Qliro\QliroOne\Api\Data\AdminAdditionsInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;

/**
 * Class AdminAdditions
 *
 * Provides implementation for managing administrative additions related to Qliro orders.
 * This includes handling payment transaction identifiers and associated order items.
 */
class AdminAdditions implements AdminAdditionsInterface
{
    /**
     * @var int
     */
    private $paymentTransactionId;

    /**
     * @var QliroOrderItemInterface[]
     */
    private array $orderItems;

    /**
     * @inheritDoc
     */
    public function getPaymentTransactionId(): int
    {
        return $this->paymentTransactionId;
    }

    /**
     * @inheritDoc
     */
    public function setPaymentTransactionId(int $value): AdminAdditionsInterface
    {
        $this->paymentTransactionId = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getOrderItems(): array
    {
        return $this->orderItems;
    }

    /**
     * @inheritDoc
     */
    public function setOrderItems(array $value): AdminAdditionsInterface
    {
        $this->orderItems = $value;

        return $this;
    }
}
