<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Api\Client;

use Qliro\QliroOne\Api\Data\AdminAddItemsToInvoiceRequestInterface;
use Qliro\QliroOne\Api\Data\AdminCancelOrderRequestInterface;
use Qliro\QliroOne\Api\Data\AdminMarkItemsAsShippedRequestInterface;
use Qliro\QliroOne\Api\Data\AdminReturnWithItemsRequestInterface;
use Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface;
use Qliro\QliroOne\Api\Data\AdminUpdateMerchantReferenceRequestInterface;

/**
 * Order Management API client interface
 *
 * @api
 */
interface OrderManagementInterface
{
    /**
     * Get QliroOne order by its Qliro Order ID
     *
     * @param int $qliroOrderId
     * @return \Qliro\QliroOne\Api\Data\AdminOrderInterface
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    public function getOrder($qliroOrderId);

    /**
     * Send a "Mark items as shipped" request
     *
     * @param \Qliro\QliroOne\Api\Data\AdminMarkItemsAsShippedRequestInterface $request
     * @param int|null $storeId
     * @return \Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    public function markItemsAsShipped(AdminMarkItemsAsShippedRequestInterface $request, $storeId = null);

    /**
     * Cancel admin QliroOne order
     *
     * @param \Qliro\QliroOne\Api\Data\AdminCancelOrderRequestInterface $request
     * @param int|null $storeId
     * @return \Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    public function cancelOrder(AdminCancelOrderRequestInterface $request, $storeId = null);

    /**
     * Update QliroOne order merchant reference
     *
     * @param \Qliro\QliroOne\Api\Data\AdminUpdateMerchantReferenceRequestInterface $request
     * @param int|null $storeId
     * @return \Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    public function updateMerchantReference(AdminUpdateMerchantReferenceRequestInterface $request, $storeId = null);

    /**
     * Make a call "Return with items"
     *
     * @param \Qliro\QliroOne\Api\Data\AdminReturnWithItemsRequestInterface $request
     * @param int|null $storeId
     * @return \Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    public function returnWithItems(AdminReturnWithItemsRequestInterface $request, $storeId = null);

    /**
     * Adds items to an invoice based on the provided request.
     *
     * @param AdminAddItemsToInvoiceRequestInterface $request The request object containing the invoice and items data.
     * @param int|null $storeId Optional store ID for identifying the store context.
     * @return AdminTransactionResponseInterface|null Returns the resulting container or null in case of failure.
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    public function addItemsToInvoice(AdminAddItemsToInvoiceRequestInterface $request, $storeId = null);

    /**
     * Get admin QliroOne order payment transaction
     *
     * @param int $paymentTransactionId
     * @param int|null $storeId
     * @return \Qliro\QliroOne\Api\Data\AdminOrderPaymentTransactionInterface
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    public function getPaymentTransaction($paymentTransactionId, $storeId = null);

    /**
     * Retry a reversal payment
     *
     * @param int $paymentReference
     * @param int|null $storeId
     * @return \Qliro\QliroOne\Api\Data\AdminOrderPaymentTransactionInterface|null
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    public function retryReversalPayment($paymentReference, $storeId = null);
}
