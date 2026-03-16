<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Client\OrderManagement;

/**
 * ISP sub-interface: payment transaction operations on a QliroOne order.
 *
 * @api
 */
interface PaymentOperationsInterface
{
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
