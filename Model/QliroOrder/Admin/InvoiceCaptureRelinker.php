<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin;

use Magento\Framework\App\ResourceConnection;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\TransactionFactory;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction as TransactionResource;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\CollectionFactory as TransactionCollectionFactory;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterface as Status;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusInterface as QliroStatus;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Api\Client\OrderManagement;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\Payment;
use Qliro\QliroOne\Model\OrderManagementStatus\Update\CaptureTransactionUpdater;
use Qliro\QliroOne\Model\ResourceModel\OrderManagementStatus as StatusResource;

/**
 * Moves an invoice that carries a Qliro transaction other than a capture onto the capture.
 *
 * Before 1.8.2 an invoice Qliro refused as already shipped took the id from the refusal, which is
 * the preauthorization. Qliro refunds against a capture only, so such an invoice could not be
 * refunded. The invoice and its capture transaction get the capture's id and captured amount.
 *
 * A refund splits by captured amount across the captures that have one and ignores the others, so
 * an order changes only as a whole: every invoice on its capture with its amount, or none.
 */
class InvoiceCaptureRelinker
{
    public const RELINKED = 'relinked';
    public const TRACKED = 'tracked';
    public const CAPTURE = 'capture';
    public const PENDING = 'pending';
    public const MANUAL = 'manual';
    public const FAILED = 'failed';

    /**
     * @param OrderFactory $orderFactory
     * @param LinkRepositoryInterface $linkRepository
     * @param OrderManagement $orderManagementApi
     * @param CaptureCoverage $captureCoverage
     * @param TransactionCollectionFactory $transactionCollectionFactory
     * @param TransactionFactory $transactionFactory
     * @param TransactionResource $transactionResource
     * @param OrderStatusHistoryRepositoryInterface $historyRepository
     * @param ResourceConnection $resource
     * @param LogManager $logManager
     */
    public function __construct(
        private readonly OrderFactory $orderFactory,
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly OrderManagement $orderManagementApi,
        private readonly CaptureCoverage $captureCoverage,
        private readonly TransactionCollectionFactory $transactionCollectionFactory,
        private readonly TransactionFactory $transactionFactory,
        private readonly TransactionResource $transactionResource,
        private readonly OrderStatusHistoryRepositoryInterface $historyRepository,
        private readonly ResourceConnection $resource,
        private readonly LogManager $logManager
    ) {
    }

    /**
     * One line per invoice of the order without a captured amount
     *
     * @param int $orderId
     * @param bool $dryRun
     * @return array<int, array{invoice: string, outcome: string, from: string, to: int|null, note: string}>
     * @throws \Exception When the order cannot be read here or at Qliro
     */
    public function relink(int $orderId, bool $dryRun): array
    {
        // Not through the repository, which keeps every order it loads for the whole run
        $order = $this->orderFactory->create()->load($orderId);
        $link = $this->linkRepository->getByOrderId($orderId, false);
        $this->logManager->setMerchantReference((string)$link->getReference());

        $qliroOrder = $this->orderManagementApi->getOrder(
            (int)$link->getQliroOrderId(),
            $order->getStoreId(),
            Config::API_PROFILE_BACKGROUND
        );
        $plan = new RelinkPlan(
            $order,
            ($qliroOrder ? $qliroOrder->getPaymentTransactions() : null) ?: [],
            $this->records($order),
            (int)$link->getQliroOrderId()
        );
        $invoices = $order->getInvoiceCollection()->getItems();
        ksort($invoices);

        /** @var Invoice $invoice */
        foreach ($invoices as $invoice) {
            $from = (string)$invoice->getTransactionId();

            // An offline invoice has no transaction, and is refunded offline
            if ((int)$invoice->getState() === Invoice::STATE_CANCELED || $from === '') {
                continue;
            }

            // Refunded through the captures once they have amounts, which this one is none of
            if (!ctype_digit($from)) {
                $plan->add($invoice, self::MANUAL, null, 'carries no Qliro capture');

                continue;
            }

            $record = $plan->record($from);
            $held = $plan->atQliro((int)$from);

            // A reconcile run can have given the preauthorization an amount, from the refusal's status row
            if ($record && $this->isTracked($record) && $held && $held->getType() === CaptureCoverage::TYPE_CAPTURE) {
                $plan->add($invoice, self::CAPTURE, (int)$from, 'already on its capture');
            } else {
                $this->planInvoice($plan, $invoice, $record);
            }
        }

        $uncarried = $plan->changes() !== [] ? $plan->uncarriedCaptures() : [];

        if (!$plan->isComplete() || $uncarried !== []) {
            $plan->hold(array_map(fn (int $id) => 'capture ' . $id . ' that no invoice carries', $uncarried));
        } elseif (!$dryRun && $plan->changes() !== []) {
            try {
                $this->apply($plan);
            } catch (\Throwable $exception) {
                $plan->fail($exception->getMessage());
            }

            if ($plan->isApplied()) {
                $this->logManager->info(
                    'Invoices moved from a transaction that is not a capture to the capture',
                    ['extra' => ['order_id' => $order->getId(), 'changes' => $plan->summary()]]
                );
            }
        }

        if ($dryRun || !$plan->isApplied()) {
            $plan->restoreInvoices();
        }

        return $plan->lines();
    }

    /**
     * @param RelinkPlan $plan
     * @param Invoice $invoice
     * @param Transaction|null $record
     * @return void
     */
    private function planInvoice(RelinkPlan $plan, Invoice $invoice, ?Transaction $record): void
    {
        $from = (int)$invoice->getTransactionId();
        $held = $plan->atQliro($from);

        if ($held === null) {
            $plan->add($invoice, self::MANUAL, null, 'Qliro holds no transaction of this id');

            return;
        }

        if ($record && $record->getTxnType() !== TransactionInterface::TYPE_CAPTURE) {
            $plan->add($invoice, self::MANUAL, null, 'its transaction is a ' . $record->getTxnType());

            return;
        }

        // A captured amount without what was refunded of it would let a refund ask for too much
        if ($plan->isRefunded()) {
            $plan->add($invoice, self::MANUAL, null, 'Qliro has refunds on this order, which capture they took from is unknown');

            return;
        }

        if ($held->getType() === CaptureCoverage::TYPE_CAPTURE) {
            if ($held->getStatus() !== QliroStatus::STATUS_SUCCESS) {
                $failed = in_array($held->getStatus(), CaptureCoverage::FAILED, true);
                $plan->add($invoice, $failed ? self::MANUAL : self::PENDING, $from, 'capture ' . $held->getStatus());

                return;
            }

            $plan->track($invoice, $record ?: $this->newCapture($plan, null, $from), $held);

            return;
        }

        $capture = $this->captureCoverage->captureForInvoice($plan->transactions(), $plan->order(), $invoice);

        if ($capture === null) {
            $plan->add($invoice, self::MANUAL, null, sprintf('a %s, no single capture fits', $held->getType()));

            return;
        }

        $captured = $plan->atQliro($capture);

        if ($captured->getStatus() !== QliroStatus::STATUS_SUCCESS) {
            $plan->add($invoice, self::PENDING, $capture, 'capture ' . $captured->getStatus());
            $plan->take($invoice, $capture);

            return;
        }

        // One no invoice carries, a shipment's confirmation can have recorded it. Magento keeps one
        // transaction per id, so invoices on one preauthorization share theirs: the last of them
        // takes it over, the others get one of their own
        $target = $plan->record((string)$capture);

        if ($target && $target->getTxnType() !== TransactionInterface::TYPE_CAPTURE) {
            $plan->add($invoice, self::MANUAL, $capture, 'the capture has a transaction of type ' . $target->getTxnType());

            return;
        }

        $last = $plan->carriers($invoice, (string)$from) === 0;

        if (!$target) {
            $target = $record && $last ? $record : $this->newCapture($plan, $record, $capture);
        } elseif ($record && $last) {
            $plan->retire($record);
        }

        $plan->relink($invoice, $target, $captured);
    }

    /**
     * @param Order $order
     * @return array<string, Transaction> by txn id
     */
    private function records(Order $order): array
    {
        $records = [];
        $collection = $this->transactionCollectionFactory->create()
            ->addPaymentIdFilter((int)$order->getPayment()->getEntityId());

        /** @var Transaction $record */
        foreach ($collection->getItems() as $record) {
            $records[(string)$record->getTxnId()] = $record;
        }

        return $records;
    }

    /**
     * @param Transaction $record
     * @return bool
     */
    private function isTracked(Transaction $record): bool
    {
        return $record->getTxnType() === TransactionInterface::TYPE_CAPTURE
            && isset(($record->getAdditionalInformation() ?: [])[CaptureTransactionUpdater::KEY_CAPTURED_AMOUNT]);
    }

    /**
     * A capture transaction under the authorization, as Magento makes it for an invoice
     *
     * @param RelinkPlan $plan
     * @param Transaction|null $shared The transaction it is split off
     * @param int $captureId
     * @return Transaction
     */
    private function newCapture(RelinkPlan $plan, ?Transaction $shared, int $captureId): Transaction
    {
        $order = $plan->order();
        $record = $this->transactionFactory->create();
        $record->setTxnId((string)$captureId);
        $record->setOrderId((int)$order->getId());
        $record->setPaymentId((int)$order->getPayment()->getEntityId());
        $record->setTxnType(TransactionInterface::TYPE_CAPTURE);
        $record->setIsClosed($shared ? (int)$shared->getIsClosed() : 0);

        // The authorization the placement records, see Payment::createPaymentTransaction
        $authorization = 'qliroone-' . $plan->qliroOrderId();
        $parent = $shared ? $shared->getParentTxnId() : null;
        $parent = $parent ?: ($plan->record($authorization) ? $authorization : null);

        if ($parent) {
            $record->setParentTxnId($parent);
        }

        $plan->remember($record);

        return $record;
    }

    /**
     * Drops what a reconcile run of the preauthorization wrote, the allocator reads the amounts
     *
     * @param Transaction $record
     * @return void
     */
    private function forget(Transaction $record): void
    {
        foreach ([
            CaptureTransactionUpdater::KEY_CAPTURED_AMOUNT,
            CaptureTransactionUpdater::KEY_CAPTURED_CURRENCY,
            CaptureRefundAllocator::KEY_REFUNDED_AMOUNT,
            'provider_result_description',
            'provider_result_code',
            'provider_transaction_id',
            'payment_reference',
        ] as $key) {
            $record->unsAdditionalInformation($key);
        }
    }

    /**
     * Every change of the order in one database transaction
     *
     * @param RelinkPlan $plan
     * @return void
     * @throws \Exception
     */
    private function apply(RelinkPlan $plan): void
    {
        $order = $plan->order();
        $connection = $this->resource->getConnection('sales');
        $connection->beginTransaction();

        try {
            foreach ($plan->changes() as $change) {
                $record = $change['record'];
                $capture = $change['capture'];
                $captureId = (string)$capture->getPaymentTransactionId();

                // What a record taken over from the preauthorization holds is not the capture's
                $own = (string)$record->getTxnId() === $captureId;

                if ($change['from'] !== null) {
                    // By column, an invoice save runs the capture observers
                    $connection->update(
                        $this->resource->getTableName('sales_invoice'),
                        ['transaction_id' => $captureId],
                        ['entity_id = ?' => (int)$change['invoice']->getId()]
                    );
                    // The refusal's status row would have a reconcile run apply the preauthorization
                    // The module's table, on the default connection
                    $this->resource->getConnection()->update(
                        $this->resource->getTableName(StatusResource::TABLE_OM_STATUS),
                        ['transaction_status' => QliroStatus::STATUS_SUCCESS],
                        [
                            'qliro_order_id = ?' => $plan->qliroOrderId(),
                            'transaction_id = ?' => $change['from'],
                            'record_type = ?' => Status::RECORD_TYPE_PAYMENT,
                            'transaction_status = ?' => QliroStatus::STATUS_CREATED,
                            'message LIKE ?' => sprintf(Payment::ADOPTED_CAPTURE_MESSAGE, '%'),
                        ]
                    );
                    $record->setTxnId($captureId);
                }

                if (!$own) {
                    $this->forget($record);
                }

                if (!$own || !$this->isTracked($record)) {
                    $record->setAdditionalInformation(CaptureTransactionUpdater::KEY_CAPTURED_AMOUNT, $capture->getAmount());
                    $record->setAdditionalInformation(CaptureTransactionUpdater::KEY_CAPTURED_CURRENCY, $capture->getCurrency());
                }

                $this->transactionResource->save($record);

                if ($change['from'] !== null) {
                    $this->historyRepository->save($order->addCommentToStatusHistory((string)__(
                        'Invoice #%1 now carries Qliro capture %2 instead of %3, which is not a capture.',
                        $change['invoice']->getIncrementId(),
                        $captureId,
                        $change['from']
                    )));
                }
            }

            foreach ($plan->stale() as $record) {
                $this->forget($record);
                $this->transactionResource->save($record);
            }

            $connection->commit();
        } catch (\Throwable $exception) {
            $connection->rollBack();

            throw $exception;
        }

        $plan->applied();
    }
}
