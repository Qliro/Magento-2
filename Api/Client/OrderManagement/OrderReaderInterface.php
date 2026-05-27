<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Client\OrderManagement;

use Qliro\QliroOne\Api\Data\AdminOrderInterface;

/**
 * ISP sub-interface: read-only access to a QliroOne order.
 *
 * @api
 */
interface OrderReaderInterface
{
    /**
     * Get QliroOne order by its Qliro Order ID
     *
     * @param int $qliroOrderId
     * @return AdminOrderInterface
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    public function getOrder(int $qliroOrderId): AdminOrderInterface;
}
