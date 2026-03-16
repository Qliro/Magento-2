<?php declare(strict_types=1);
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\MerchantPayment;

use Qliro\QliroOne\Api\Data\AdminCreateMerchantPaymentResponseInterface;

/**
 * QliroOne Create Merchant Payment response
 */
class CreateResponse implements AdminCreateMerchantPaymentResponseInterface
{
    /**
     * @var int|null
     */
    private ?int $orderId = null;

    /**
     * @var \Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface[]
     */
    private array $paymentTransactions = [];

    /**
     * @param int $orderId
     * @return void
     */
    public function setOrderId(int $orderId): void
    {
        $this->orderId = $orderId;
    }

    /**
     * @inheritDoc
     */
    public function getOrderId(): ?int
    {
        return $this->orderId;
    }

    /**
     * @param \Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface[] $transactions
     * @return void
     */
    public function setPaymentTransactions(array $transactions): void
    {
        $this->paymentTransactions = $transactions;
    }

    /**
     * @return \Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface[]
     */
    public function getPaymentTransactions(): array
    {
        return $this->paymentTransactions;
    }
}
