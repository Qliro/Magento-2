<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Client\OrderManagement;

use Qliro\QliroOne\Api\Data\AdminOrderPaymentTransactionInterface;

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
     * @return AdminOrderPaymentTransactionInterface
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    public function getPaymentTransaction(int $paymentTransactionId, int|string|null $storeId = null): AdminOrderPaymentTransactionInterface;

    /**
     * Retry a reversal payment
     *
     * @param mixed $paymentReference
     * @param int|null $storeId
     * @return AdminOrderPaymentTransactionInterface|null
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    public function retryReversalPayment(mixed $paymentReference, int|string|null $storeId = null): ?AdminOrderPaymentTransactionInterface;
}
