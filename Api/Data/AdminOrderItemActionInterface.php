<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Admin Order Item Action interface
 */
interface AdminOrderItemActionInterface extends QliroOrderItemInterface
{
    /**
     * @return string
     */
    public function getActionType();

    /**
     * @return int
     */
    public function getPaymentTransactionId();

    /**
     * @var string $value
     */
    public function setActionType($value);

    /**
     * @var int $value
     */
    public function setPaymentTransactionId($value);
}
