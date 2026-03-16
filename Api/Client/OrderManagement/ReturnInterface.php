<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Client\OrderManagement;

use Qliro\QliroOne\Api\Data\AdminReturnWithItemsRequestInterface;

/**
 * ISP sub-interface: return operations on a QliroOne order.
 *
 * @api
 */
interface ReturnInterface
{
    /**
     * Make a call "Return with items"
     *
     * @param AdminReturnWithItemsRequestInterface $request
     * @param int|null $storeId
     * @return \Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    public function returnWithItems(AdminReturnWithItemsRequestInterface $request, $storeId = null);
}
