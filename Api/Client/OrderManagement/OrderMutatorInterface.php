<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Client\OrderManagement;

use Qliro\QliroOne\Api\Data\AdminCancelOrderRequestInterface;
use Qliro\QliroOne\Api\Data\AdminMarkItemsAsShippedRequestInterface;
use Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface;
use Qliro\QliroOne\Api\Data\AdminUpdateMerchantReferenceRequestInterface;
use Qliro\QliroOne\Model\Api\Client\Exception\ClientException;

/**
 * ISP sub-interface: mutating operations on a QliroOne order.
 *
 * @api
 */
interface OrderMutatorInterface
{
    /**
     * Send a "Mark items as shipped" request
     *
     * @param AdminMarkItemsAsShippedRequestInterface $request
     * @param int|string|null $storeId
     * @return AdminTransactionResponseInterface
     * @throws ClientException
     */
    public function markItemsAsShipped(AdminMarkItemsAsShippedRequestInterface $request, int|string|null $storeId = null): AdminTransactionResponseInterface;

    /**
     * Cancel admin QliroOne order
     *
     * @param AdminCancelOrderRequestInterface $request
     * @param int|string|null $storeId
     * @return AdminTransactionResponseInterface
     * @throws ClientException
     */
    public function cancelOrder(AdminCancelOrderRequestInterface $request, int|string|null $storeId = null): AdminTransactionResponseInterface;

    /**
     * Update QliroOne order merchant reference
     *
     * @param AdminUpdateMerchantReferenceRequestInterface $request
     * @param int|string|null $storeId
     * @return AdminTransactionResponseInterface|null
     * @throws ClientException
     */
    public function updateMerchantReference(AdminUpdateMerchantReferenceRequestInterface $request, int|string|null $storeId = null): ?AdminTransactionResponseInterface;
}
