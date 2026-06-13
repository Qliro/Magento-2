<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\OrderManagementStatus\Update;

use Magento\Sales\Api\TransactionRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * Saves capture details from a Qliro TransactionStatus notification
 * onto the corresponding payment transaction (sales_payment_transaction.additional_information)
 */
class CaptureTransactionUpdater
{
    public const KEY_CAPTURED_AMOUNT = 'captured_amount';
    public const KEY_CAPTURED_CURRENCY = 'captured_currency';

    /**
     * @param TransactionRepositoryInterface $transactionRepository
     * @param LogManager $logManager
     */
    public function __construct(
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly LogManager $logManager
    ) {
    }

    /**
     * Store captured amount and provider details on the capture payment transaction.
     *
     * The capture transaction is created with txn_id equal to the Qliro PaymentTransactionId,
     * so it can be looked up directly. Failures are logged but not rethrown, since this data
     * is informational (used later, e.g. when creating credit memo refunds).
     *
     * @param \Qliro\QliroOne\Model\Notification\QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param int $paymentId
     * @param int $orderId
     * @return void
     */
    public function update($qliroOrderManagementStatus, $paymentId, $orderId): void
    {
        try {
            $paymentTransaction = $this->transactionRepository->getByTransactionId(
                $qliroOrderManagementStatus->getPaymentTransactionId(),
                $paymentId,
                $orderId
            );

            if (!$paymentTransaction || !$paymentTransaction->getId()) {
                $this->logManager->debug(
                    'No payment transaction found to store captured amount',
                    [
                        'extra' => [
                            'qliro_order_id' => $qliroOrderManagementStatus->getOrderId(),
                            'transaction_id' => $qliroOrderManagementStatus->getPaymentTransactionId(),
                        ],
                    ]
                );

                return;
            }

            $paymentTransaction->setAdditionalInformation(
                self::KEY_CAPTURED_AMOUNT,
                $qliroOrderManagementStatus->getAmount()
            );
            $paymentTransaction->setAdditionalInformation(
                self::KEY_CAPTURED_CURRENCY,
                $qliroOrderManagementStatus->getCurrency()
            );
            $paymentTransaction->setAdditionalInformation(
                'provider_result_description',
                $qliroOrderManagementStatus->getProviderResultDescription()
            );
            $paymentTransaction->setAdditionalInformation(
                'provider_result_code',
                $qliroOrderManagementStatus->getProviderResultCode()
            );
            $paymentTransaction->setAdditionalInformation(
                'provider_transaction_id',
                $qliroOrderManagementStatus->getProviderTransactionId()
            );
            $paymentTransaction->setAdditionalInformation(
                'payment_reference',
                $qliroOrderManagementStatus->getPaymentReference()
            );

            $this->transactionRepository->save($paymentTransaction);
        } catch (\Exception $exception) {
            $this->logManager->debug(
                $exception,
                [
                    'extra' => [
                        'qliro_order_id' => $qliroOrderManagementStatus->getOrderId(),
                        'transaction_id' => $qliroOrderManagementStatus->getPaymentTransactionId(),
                        'payment_id' => $paymentId,
                    ],
                ]
            );
        }
    }
}
