<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use Qliro\QliroOne\Api\Data\AdminOrderPaymentTransactionInterface;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusInterface as QliroStatus;

/**
 * What InvoiceCaptureRelinker decided for the invoices of one order, and the changes that follow
 */
class RelinkPlan
{
    private const TYPE_REFUND = 'Refund';

    /**
     * A refund through AddItemsToInvoice shows as this, of a negative amount
     */
    private const TYPE_UPDATE_INVOICE = 'UpdateInvoice';

    /** @var array<int, AdminOrderPaymentTransactionInterface> */
    private array $atQliro = [];

    /** @var array<int, array{invoice: string, outcome: string, from: string, to: int|null, note: string}> */
    private array $lines = [];

    /** @var array<int, array{invoice: Invoice, record: Transaction, capture: AdminOrderPaymentTransactionInterface, from: int|null}> */
    private array $changes = [];

    /** @var array<int, array{0: Invoice, 1: string}> the transaction each moved invoice had */
    private array $moved = [];

    /** @var Transaction[] preauthorization records no invoice carries any more */
    private array $stale = [];

    private bool $applied = false;

    /**
     * @param Order $order
     * @param AdminOrderPaymentTransactionInterface[] $transactions
     * @param array<string, Transaction> $records Magento's transactions of the payment by txn id
     * @param int $qliroOrderId
     */
    public function __construct(
        private readonly Order $order,
        array $transactions,
        private array $records,
        private readonly int $qliroOrderId
    ) {
        foreach ($transactions as $transaction) {
            $this->atQliro[(int)$transaction->getPaymentTransactionId()] = $transaction;
        }
    }

    public function order(): Order
    {
        return $this->order;
    }

    public function qliroOrderId(): int
    {
        return $this->qliroOrderId;
    }

    /**
     * @return AdminOrderPaymentTransactionInterface[]
     */
    public function transactions(): array
    {
        return array_values($this->atQliro);
    }

    public function atQliro(int $id): ?AdminOrderPaymentTransactionInterface
    {
        return $this->atQliro[$id] ?? null;
    }

    public function record(string $txnId): ?Transaction
    {
        return $this->records[$txnId] ?? null;
    }

    /**
     * A transaction made for the plan, found by its id from now on
     */
    public function remember(Transaction $record): void
    {
        $this->records[(string)$record->getTxnId()] = $record;
    }

    /**
     * Settled captures at Qliro that no invoice carries now, a refund could not reach them
     *
     * @return int[]
     */
    public function uncarriedCaptures(): array
    {
        $carried = [];

        foreach ($this->order->getInvoiceCollection() as $invoice) {
            if ((int)$invoice->getState() !== Invoice::STATE_CANCELED) {
                $carried[] = (string)$invoice->getTransactionId();
            }
        }

        $uncarried = [];

        foreach ($this->atQliro as $id => $transaction) {
            if ($transaction->getType() === CaptureCoverage::TYPE_CAPTURE
                && $transaction->getStatus() === QliroStatus::STATUS_SUCCESS
                && !in_array((string)$id, $carried, true)
            ) {
                $uncarried[] = $id;
            }
        }

        return $uncarried;
    }

    /**
     * Whether Qliro refunded part of the order, a refund it failed aside
     */
    public function isRefunded(): bool
    {
        foreach ($this->atQliro as $transaction) {
            $refund = $transaction->getType() === self::TYPE_REFUND
                || ($transaction->getType() === self::TYPE_UPDATE_INVOICE && (float)$transaction->getAmount() < 0);

            if ($refund
                && !in_array($transaction->getStatus(), CaptureCoverage::FAILED, true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Other invoices of the order that carry this transaction now
     */
    public function carriers(Invoice $invoice, string $transactionId): int
    {
        $count = 0;

        foreach ($this->order->getInvoiceCollection() as $other) {
            if ((int)$other->getId() !== (int)$invoice->getId()
                && (int)$other->getState() !== Invoice::STATE_CANCELED
                && (string)$other->getTransactionId() === $transactionId
            ) {
                $count++;
            }
        }

        return $count;
    }

    public function add(Invoice $invoice, string $outcome, ?int $to, string $note): void
    {
        $this->lines[] = [
            'invoice' => (string)$invoice->getIncrementId(),
            'outcome' => $outcome,
            'from' => (string)$invoice->getTransactionId(),
            'to' => $to,
            'note' => $note,
        ];
    }

    /**
     * The invoice carries this capture from now on, for the next invoice's choice
     */
    public function take(Invoice $invoice, int $captureId): void
    {
        $this->moved[] = [$invoice, (string)$invoice->getTransactionId()];
        $invoice->setTransactionId((string)$captureId);
    }

    public function relink(Invoice $invoice, Transaction $record, AdminOrderPaymentTransactionInterface $capture): void
    {
        $captureId = (int)$capture->getPaymentTransactionId();
        $this->add($invoice, InvoiceCaptureRelinker::RELINKED, $captureId, (string)$capture->getAmount());
        $this->changes[] = [
            'invoice' => $invoice,
            'record' => $record,
            'capture' => $capture,
            'from' => (int)$invoice->getTransactionId(),
        ];
        $this->take($invoice, $captureId);
    }

    /**
     * A record the moved invoices leave behind, it must not count as a capture with money left
     */
    public function retire(Transaction $record): void
    {
        $this->stale[] = $record;
    }

    /**
     * @return Transaction[]
     */
    public function stale(): array
    {
        return $this->stale;
    }

    public function track(Invoice $invoice, Transaction $record, AdminOrderPaymentTransactionInterface $capture): void
    {
        $this->add(
            $invoice,
            InvoiceCaptureRelinker::TRACKED,
            (int)$capture->getPaymentTransactionId(),
            (string)$capture->getAmount()
        );

        // Invoices on one capture share its transaction
        foreach ($this->changes as $change) {
            if ($change['record'] === $record) {
                return;
            }
        }

        $this->changes[] = ['invoice' => $invoice, 'record' => $record, 'capture' => $capture, 'from' => null];
    }

    /**
     * Whether every invoice listed ends on its capture with its amount
     */
    public function isComplete(): bool
    {
        foreach ($this->lines as $line) {
            if (!$this->isChange($line) && $line['outcome'] !== InvoiceCaptureRelinker::CAPTURE) {
                return false;
            }
        }

        return true;
    }

    /**
     * Nothing of the order changes, the invoices that would have are left for a person too
     *
     * @param string[] $reasons Besides the invoices that cannot be moved
     */
    public function hold(array $reasons = []): void
    {
        $this->changes = [];
        $this->stale = [];
        $blocking = $reasons;

        foreach ($this->lines as $line) {
            if (!$this->isChange($line) && $line['outcome'] !== InvoiceCaptureRelinker::CAPTURE) {
                $blocking[] = 'invoice #' . $line['invoice'];
            }
        }

        foreach ($this->lines as &$line) {
            if ($this->isChange($line)) {
                $line['note'] = sprintf('would be %s %s, held for %s', $line['outcome'], $line['to'], implode(', ', $blocking));
                $line['outcome'] = InvoiceCaptureRelinker::MANUAL;
            }
        }
        unset($line);
    }

    /**
     * @param array{outcome: string} $line
     */
    private function isChange(array $line): bool
    {
        return in_array($line['outcome'], [InvoiceCaptureRelinker::RELINKED, InvoiceCaptureRelinker::TRACKED], true);
    }

    public function fail(string $message): void
    {
        foreach ($this->lines as &$line) {
            if ($this->isChange($line)) {
                $line['outcome'] = InvoiceCaptureRelinker::FAILED;
                $line['note'] = $message;
            }
        }
        unset($line);
    }

    /**
     * @return array<int, array{invoice: Invoice, record: Transaction, capture: AdminOrderPaymentTransactionInterface, from: int|null}>
     */
    public function changes(): array
    {
        return $this->changes;
    }

    public function applied(): void
    {
        $this->applied = true;
    }

    public function isApplied(): bool
    {
        return $this->applied;
    }

    /**
     * The invoices back on the transaction they have in the database
     */
    public function restoreInvoices(): void
    {
        foreach (array_reverse($this->moved) as [$invoice, $transactionId]) {
            $invoice->setTransactionId($transactionId);
        }

        $this->moved = [];
    }

    /**
     * @return array<int, array{invoice: string, outcome: string, from: string, to: int|null, note: string}>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * @return array<int, string>
     */
    public function summary(): array
    {
        return array_map(
            fn (array $line) => sprintf('%s %s %s -> %s', $line['invoice'], $line['outcome'], $line['from'], $line['to']),
            $this->lines
        );
    }
}
