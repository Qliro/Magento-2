<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Client;

use Qliro\QliroOne\Api\Client\OrderManagement\OrderMutatorInterface;
use Qliro\QliroOne\Api\Client\OrderManagement\OrderReaderInterface;
use Qliro\QliroOne\Api\Client\OrderManagement\PaymentOperationsInterface;
use Qliro\QliroOne\Api\Client\OrderManagement\ReturnInterface;

/**
 * Order Management API client interface
 *
 * Extends the four focused ISP sub-interfaces for backward compatibility.
 * Callers that only need a subset of operations should type-hint the
 * narrower sub-interface instead.
 *
 * @api
 */
interface OrderManagementInterface extends
    OrderReaderInterface,
    OrderMutatorInterface,
    PaymentOperationsInterface,
    ReturnInterface
{
}
