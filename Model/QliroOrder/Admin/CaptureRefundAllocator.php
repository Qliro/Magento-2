<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Admin;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\TransactionRepositoryInterface;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\OrderManagementStatus\Update\CaptureTransactionUpdater;

/**
 * Allocates a refund amount across Qliro capture transactions.
 *
 * Qliro validates each Addition against the amount left in its own capture, so a credit memo may
 * span several captures. Splits the refund by left = captured_amount - refunded_amount (both
 * tracked in sales_payment_transaction.additional_information).
 */
class CaptureRefundAllocator
{
    public const KEY_REFUNDED_AMOUNT = 'refunded_amount';

    /**
     * Threshold for treating a leftover as zero (avoids float noise)
     */
    private const EPSILON = 0.005;

    /**
     * @param TransactionRepositoryInterface $transactionRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param LogManager $logManager
     */
    public function __construct(
        private readonly TransactionRepositoryInterface $transactionRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly LogManager $logManager
    ) {
    }

    /**
     * Allocate a refund amount across the payment's capture transactions.
     *
     * Returns a list of ['payment_transaction_id' => int, 'amount' => float] entries with
     * positive amounts. The capture belonging to the credit memo's invoice is drained first,
     * then remaining captures in chronological order.
     *
     * Returns an empty array when no capture has captured_amount data (orders captured before
     * tracking was introduced) - the caller should fall back to legacy single-capture behavior.
     *
     * @param Payment $payment
     * @param float $refundAmount Positive amount in order currency
     * @return array
     * @throws LocalizedException When tracked captures cannot cover the refund amount
     */
    public function allocate(Payment $payment, float $refundAmount): array
    {
        $captures = $this->getCaptures($payment);

        if (empty($captures)) {
            return [];
        }

        $preferredTxnId = $this->getPreferredTxnId($payment);

        usort($captures, function ($a, $b) use ($preferredTxnId) {
            if ($a['txn_id'] === $preferredTxnId) {
                return -1;
            }
            if ($b['txn_id'] === $preferredTxnId) {
                return 1;
            }

            return strcmp($a['created_at'], $b['created_at']);
        });

        $allocation = [];
        $remaining = round($refundAmount, 2);

        foreach ($captures as $capture) {
            if ($remaining < self::EPSILON) {
                break;
            }

            if ($capture['left'] < self::EPSILON) {
                continue;
            }

            $take = round(min($capture['left'], $remaining), 2);

            $allocation[] = [
                'payment_transaction_id' => (int)$capture['txn_id'],
                'amount' => $take,
            ];

            $remaining = round($remaining - $take, 2);
        }

        if ($remaining >= self::EPSILON) {
            $totalLeft = round($refundAmount - $remaining, 2);

            $this->logManager->debug(
                'Refund amount exceeds total amount left in Qliro captures',
                [
                    'extra' => [
                        'order_id' => $payment->getOrder()->getId(),
                        'refund_amount' => $refundAmount,
                        'total_left' => $totalLeft,
                        'captures' => $captures,
                    ],
                ]
            );

            throw new LocalizedException(__(
                'Unable to refund %1: only %2 is left to refund across Qliro captures.',
                $refundAmount,
                $totalLeft
            ));
        }

        return $allocation;
    }

    /**
     * Record a confirmed refund against a single capture transaction.
     *
     * Used from the success callback once the PSP has actually confirmed the return, so
     * refunded_amount only ever reflects money that really left the capture.
     *
     * @param Payment $payment
     * @param string $captureTxnId Qliro capture PaymentTransactionId
     * @param float $amount Positive refunded amount
     * @return void
     */
    public function registerRefundForCapture(Payment $payment, string $captureTxnId, float $amount): void
    {
        $this->registerRefunds($payment, [
            [
                'payment_transaction_id' => $captureTxnId,
                'amount' => $amount,
            ],
        ]);
    }

    /**
     * Record refunded amounts on the capture transactions after Qliro accepted the refund.
     *
     * Failures are logged but not rethrown - the refund has already been accepted upstream.
     *
     * @param Payment $payment
     * @param array $allocation Same structure as returned by allocate()
     * @return void
     */
    public function registerRefunds(Payment $payment, array $allocation): void
    {
        foreach ($allocation as $entry) {
            try {
                $transaction = $this->transactionRepository->getByTransactionId(
                    (string)$entry['payment_transaction_id'],
                    $payment->getId(),
                    $payment->getOrder()->getId()
                );

                if (!$transaction || !$transaction->getId()) {
                    continue;
                }

                $info = $transaction->getAdditionalInformation() ?: [];
                $refunded = (float)($info[self::KEY_REFUNDED_AMOUNT] ?? 0);

                $transaction->setAdditionalInformation(
                    self::KEY_REFUNDED_AMOUNT,
                    round($refunded + (float)$entry['amount'], 2)
                );

                $this->transactionRepository->save($transaction);
            } catch (\Exception $exception) {
                $this->logManager->debug(
                    $exception,
                    [
                        'extra' => [
                            'order_id' => $payment->getOrder()->getId(),
                            'transaction_id' => $entry['payment_transaction_id'],
                            'amount' => $entry['amount'],
                        ],
                    ]
                );
            }
        }
    }

    /**
     * Collect capture transactions that carry captured_amount tracking data.
     *
     * @param Payment $payment
     * @return array List of ['txn_id', 'created_at', 'captured', 'refunded', 'left']
     */
    private function getCaptures(Payment $payment): array
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('payment_id', $payment->getId())
            ->addFilter('txn_type', Transaction::TYPE_CAPTURE)
            ->create();

        $transactions = $this->transactionRepository->getList($searchCriteria)->getItems();
        $captures = [];

        foreach ($transactions as $transaction) {
            $info = $transaction->getAdditionalInformation() ?: [];

            if (!isset($info[CaptureTransactionUpdater::KEY_CAPTURED_AMOUNT])) {
                continue;
            }

            $captured = (float)$info[CaptureTransactionUpdater::KEY_CAPTURED_AMOUNT];
            $refunded = (float)($info[self::KEY_REFUNDED_AMOUNT] ?? 0);

            $captures[] = [
                'txn_id' => (string)$transaction->getTxnId(),
                'created_at' => (string)$transaction->getCreatedAt(),
                'captured' => $captured,
                'refunded' => $refunded,
                'left' => round($captured - $refunded, 2),
            ];
        }

        return $captures;
    }

    /**
     * The capture transaction of the credit memo's invoice should be drained first.
     *
     * @param Payment $payment
     * @return string|null
     */
    private function getPreferredTxnId(Payment $payment): ?string
    {
        $creditMemo = $payment->getCreditmemo();

        if ($creditMemo && $creditMemo->getInvoice() && $creditMemo->getInvoice()->getTransactionId()) {
            return (string)$creditMemo->getInvoice()->getTransactionId();
        }

        return null;
    }
}
