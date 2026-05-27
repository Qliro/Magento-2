<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Admin Create Merchant Payment Response interface
 *
 * @api
 */
interface AdminCreateMerchantPaymentResponseInterface
{
    /**
     * Set Qliro order ID from the payment response
     *
     * @param int $orderId
     * @return void
     */
    public function setOrderId(int $orderId): void;

    /**
     * Get Qliro order ID from the payment response
     *
     * @return int|null
     */
    public function getOrderId(): ?int;

    /**
     * Set payment transactions from the response
     *
     * @param AdminOrderPaymentTransactionInterface[] $transactions
     * @return void
     */
    public function setPaymentTransactions(array $transactions): void;

    /**
     * Get payment transactions from the response
     *
     * @return AdminOrderPaymentTransactionInterface[]
     */
    public function getPaymentTransactions(): array;
}
