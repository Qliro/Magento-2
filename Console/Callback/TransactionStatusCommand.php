<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

// @codingStandardsIgnoreFile
// phpcs:ignoreFile

namespace Qliro\QliroOne\Console\Callback;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Simulate a Qliro TransactionStatus push callback locally.
 *
 * Qliro sends this to MerchantOrderManagementStatusPushUrl when a payment
 * transaction changes state (capture, refund, cancel, etc.).
 *
 * The handler (Management\TransactionStatus::handle) reads:
 *   OrderId, PaymentTransactionId, Status
 *
 * Transaction statuses that trigger handlers:
 *   Success | Cancelled | Error | InProcess | OnHold | UserInteraction | Created
 *
 * Usage:
 *   bin/magento qliroone:callback:transaction-status <qliro_order_id>
 *   bin/magento qliroone:callback:transaction-status <qliro_order_id> --transaction-id=<id>
 *   bin/magento qliroone:callback:transaction-status <qliro_order_id> --status=Cancelled
 *   bin/magento qliroone:callback:transaction-status <qliro_order_id> --dry-run
 */
class TransactionStatusCommand extends AbstractCallbackCommand
{
    protected function getCommandName(): string
    {
        return 'qliroone:callback:transaction-status';
    }

    protected function getCommandDescription(): string
    {
        return '[Dev] Simulate a Qliro TransactionStatus push to the local store';
    }

    protected function getCallbackPath(): string
    {
        return 'checkout/qliro_callback/transactionStatus';
    }

    protected function configureExtra(): void
    {
        $this->addOption(
            'transaction-id',
            null,
            InputOption::VALUE_OPTIONAL,
            'PaymentTransactionId to use. Defaults to the first transaction on the Qliro order.'
        );
        $this->addOption(
            'status',
            null,
            InputOption::VALUE_OPTIONAL,
            'Transaction status to send: Success|Cancelled|Error|InProcess|OnHold|UserInteraction|Created. '
                . 'Defaults to the status of the selected transaction.'
        );
    }

    /**
     * Payload mirrors what Qliro sends to MerchantOrderManagementStatusPushUrl.
     *
     * The management class reads: OrderId, PaymentTransactionId, Status.
     *
     * If --transaction-id is omitted the first PaymentTransaction from the live
     * Qliro order is used.  If --status is omitted the transaction's own status
     * is forwarded unchanged.
     */
    protected function buildPayload(array $qliroOrder, InputInterface $input): ?array
    {
        $transactions = $qliroOrder['PaymentTransactions'] ?? [];

        // Resolve which transaction to simulate
        $transactionIdOpt = $input->getOption('transaction-id');
        if ($transactionIdOpt) {
            $transaction = null;
            foreach ($transactions as $t) {
                if ((string)($t['PaymentTransactionId'] ?? '') === (string)$transactionIdOpt) {
                    $transaction = $t;
                    break;
                }
            }
            if ($transaction === null) {
                // Not found in list — build a minimal stub so the handler can still be tested
                $transaction = ['PaymentTransactionId' => $transactionIdOpt, 'Status' => 'Success', 'Amount' => null];
            }
        } else {
            $transaction = !empty($transactions) ? reset($transactions) : [];
        }

        $status = $input->getOption('status') ?: ($transaction['Status'] ?? 'Success');

        return [
            'OrderId'              => $qliroOrder['OrderId'] ?? null,
            'MerchantReference'    => $qliroOrder['MerchantReference'] ?? null,
            'PaymentTransactionId' => $transaction['PaymentTransactionId'] ?? null,
            'Status'               => $status,
            'Amount'               => $transaction['Amount'] ?? null,
        ];
    }
}
