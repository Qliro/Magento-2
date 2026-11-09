<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Admin;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\TransactionFactory;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction as TransactionResource;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\Collection as TransactionCollection;
use Magento\Sales\Model\ResourceModel\Order\Payment\Transaction\CollectionFactory as TransactionCollectionFactory;
use Magento\Sales\Model\ResourceModel\Order\Invoice\Collection as InvoiceCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Model\Api\Client\OrderManagement;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\Admin\AdminOrder;
use Qliro\QliroOne\Model\QliroOrder\Admin\CaptureCoverage;
use Qliro\QliroOne\Model\QliroOrder\Admin\InvoiceCaptureRelinker;
use Qliro\QliroOne\Model\QliroOrder\Admin\OrderPaymentTransaction;

/**
 * An invoice Qliro refused as already shipped took the preauthorization from the refusal, which
 * Qliro does not refund against
 *
 * @see \Qliro\QliroOne\Model\QliroOrder\Admin\InvoiceCaptureRelinker
 */
class InvoiceCaptureRelinkerTest extends TestCase
{
    private const PREAUTH = 324964039;

    /** @var Invoice[] by id */
    private array $invoices = [];

    /** @var array<string, Transaction> Magento payment transactions by txn id */
    private array $records = [];

    /** @var OrderPaymentTransaction[] */
    private array $atQliro = [];

    /** @var array<int, array{0: string, 1: array, 2: array}> */
    private array $updates = [];

    /** @var Transaction[] */
    private array $saved = [];

    private int $comments = 0;

    private AdapterInterface&MockObject $connection;

    protected function setUp(): void
    {
        $this->invoices = $this->records = $this->atQliro = $this->updates = $this->saved = [];
        $this->comments = 0;
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('update')->willReturnCallback(function ($table, $bind, $where) {
            $this->updates[] = [$table, $bind, $where];
            return 1;
        });
    }

    public function testAnInvoiceOnThePreauthorizationMovesToTheCapture(): void
    {
        $this->invoice(9, '000000009', (string)self::PREAUTH, 527.0);
        $this->record((string)self::PREAUTH);
        $this->qliro(self::PREAUTH, 'Preauthorization', 'Success', 527.0);
        $this->qliro(331743203, 'Capture', 'Success', 527.0);

        $this->connection->expects(self::once())->method('commit');
        $lines = $this->relinker()->relink(1, false);

        self::assertSame(InvoiceCaptureRelinker::RELINKED, $lines[0]['outcome']);
        self::assertSame(331743203, $lines[0]['to']);
        self::assertSame(['sales_invoice', ['transaction_id' => '331743203'], ['entity_id = ?' => 9]], $this->updates[0]);
        self::assertCount(1, $this->saved);
        self::assertSame('331743203', $this->saved[0]->getTxnId());
        self::assertSame(527.0, $this->saved[0]->getAdditionalInformation('captured_amount'));
        self::assertSame('SEK', $this->saved[0]->getAdditionalInformation('captured_currency'));
        self::assertSame(1, $this->comments);
    }

    public function testADryRunChangesNothing(): void
    {
        $this->invoice(9, '000000009', (string)self::PREAUTH, 527.0);
        $this->record((string)self::PREAUTH);
        $this->qliro(self::PREAUTH, 'Preauthorization', 'Success', 527.0);
        $this->qliro(331743203, 'Capture', 'Success', 527.0);

        $lines = $this->relinker()->relink(1, true);

        self::assertSame(InvoiceCaptureRelinker::RELINKED, $lines[0]['outcome']);
        self::assertSame([], $this->updates);
        self::assertSame([], $this->saved);
        self::assertSame(0, $this->comments);
        self::assertSame((string)self::PREAUTH, $this->records[(string)self::PREAUTH]->getTxnId());
    }

    /**
     * Two invoices of one amount on the preauthorization, two captures of that amount: one each
     */
    public function testTwoInvoicesOfOneAmountGetACaptureEach(): void
    {
        $this->invoice(8, '000000008', (string)self::PREAUTH, 34.0);
        $this->invoice(9, '000000009', (string)self::PREAUTH, 34.0);
        $this->record((string)self::PREAUTH);
        $this->qliro(self::PREAUTH, 'Preauthorization', 'Success', 68.0);
        $this->qliro(331743203, 'Capture', 'Success', 34.0);
        $this->qliro(331750001, 'Capture', 'Success', 34.0);

        $lines = $this->relinker()->relink(1, false);

        self::assertSame([331743203, 331750001], array_column($lines, 'to'));
        // The first gets a transaction of its own, the last takes the shared one over
        self::assertSame(['331743203', '331750001'], array_map(fn ($record) => $record->getTxnId(), $this->saved));
        self::assertNull($this->saved[0]->getId());
        self::assertSame('qliroone-4711', $this->saved[0]->getParentTxnId());
        self::assertSame(5, $this->saved[0]->getPaymentId());
        self::assertSame($this->records[(string)self::PREAUTH], $this->saved[1]);
    }

    public function testAnInvoiceWithoutATransactionGetsOne(): void
    {
        $this->invoice(9, '000000009', (string)self::PREAUTH, 527.0);
        $this->qliro(self::PREAUTH, 'Preauthorization', 'Success', 527.0);
        $this->qliro(331743203, 'Capture', 'Success', 527.0);

        self::assertSame(InvoiceCaptureRelinker::RELINKED, $this->relinker()->relink(1, false)[0]['outcome']);
        self::assertSame('capture', $this->saved[0]->getTxnType());
        self::assertSame(527.0, $this->saved[0]->getAdditionalInformation('captured_amount'));
    }

    /**
     * A Debit is no capture either, a capture is added to it when the order is shipped
     */
    public function testAnInvoiceOnADebitMovesToTheCapture(): void
    {
        $this->invoice(9, '000000009', '3453204', 199.0);
        $this->record('3453204');
        $this->qliro(3453204, 'Debit', 'Success', 199.0);
        $this->qliro(3453205, 'Capture', 'Success', 199.0);

        self::assertSame(3453205, $this->relinker()->relink(1, false)[0]['to']);
    }

    /**
     * A refund splits by captured amount, which a capture whose confirmation was lost lacks
     */
    public function testAnInvoiceOnACaptureGetsItsAmount(): void
    {
        $this->invoice(9, '000000009', '331743203', 527.0);
        $this->record('331743203');
        $this->qliro(331743203, 'Capture', 'Success', 527.0);

        $lines = $this->relinker()->relink(1, false);

        self::assertSame(InvoiceCaptureRelinker::TRACKED, $lines[0]['outcome']);
        self::assertSame(527.0, $this->saved[0]->getAdditionalInformation('captured_amount'));
        self::assertSame([], $this->updates);
        self::assertSame(0, $this->comments);
    }

    /**
     * Captured amounts on some captures only would send this invoice's refund to the others
     */
    public function testAnInvoiceThatCannotBeMovedHoldsTheWholeOrder(): void
    {
        $this->invoice(8, '000000008', '331743203', 100.0);
        $this->record('331743203');
        $this->invoice(9, '000000009', (string)self::PREAUTH, 300.0);
        $this->record((string)self::PREAUTH);
        $this->qliro(self::PREAUTH, 'Preauthorization', 'Success', 400.0);
        $this->qliro(331743203, 'Capture', 'Success', 100.0);
        $this->qliro(331750001, 'Capture', 'Success', 250.0);

        $lines = $this->relinker()->relink(1, false);

        self::assertSame([InvoiceCaptureRelinker::MANUAL, InvoiceCaptureRelinker::MANUAL], array_column($lines, 'outcome'));
        self::assertStringContainsString('invoice #000000009', $lines[0]['note']);
        self::assertSame([], $this->saved);
        self::assertSame([], $this->updates);
    }

    public function testAPendingCaptureHoldsTheOrderAndIsTakenByOneInvoiceOnly(): void
    {
        $invoice = $this->invoice(8, '000000008', (string)self::PREAUTH, 500.0);
        $this->invoice(9, '000000009', (string)self::PREAUTH, 500.0);
        $this->record((string)self::PREAUTH);
        $this->qliro(self::PREAUTH, 'Preauthorization', 'Success', 1000.0);
        $this->qliro(331743203, 'Capture', 'Created', 500.0);
        $this->qliro(331750001, 'Capture', 'Success', 500.0);

        $lines = $this->relinker()->relink(1, false);

        // The settled capture first, the pending one is left to the other invoice
        self::assertSame([331750001, 331743203], array_column($lines, 'to'));
        self::assertSame([InvoiceCaptureRelinker::MANUAL, InvoiceCaptureRelinker::PENDING], array_column($lines, 'outcome'));
        self::assertSame([], $this->saved);
        self::assertSame((string)self::PREAUTH, $invoice->getTransactionId());
    }

    public function testAnInvoiceWhoseCaptureAmountIsKnownIsLeftAsItIs(): void
    {
        $this->invoice(9, '000000009', '331743203', 527.0);
        $this->record('331743203', ['captured_amount' => 527.0]);
        $this->qliro(331743203, 'Capture', 'Success', 527.0);
        $this->invoice(10, '000000010', '', 0.0);

        $lines = $this->relinker()->relink(1, false);

        self::assertSame([InvoiceCaptureRelinker::CAPTURE], array_column($lines, 'outcome'));
        self::assertSame([], $this->saved);
    }

    public function testNoCaptureThatFitsIsLeftForAPerson(): void
    {
        $this->invoice(9, '000000009', (string)self::PREAUTH, 527.0);
        $this->record((string)self::PREAUTH);
        $this->qliro(self::PREAUTH, 'Preauthorization', 'Success', 527.0);
        $this->qliro(331743203, 'Capture', 'Success', 200.0);
        $this->qliro(331750001, 'Capture', 'Success', 327.0);

        $lines = $this->relinker()->relink(1, false);

        self::assertSame(InvoiceCaptureRelinker::MANUAL, $lines[0]['outcome']);
        self::assertSame([], $this->saved);
    }

    public function testACaptureQliroHasNotSettledIsWaitedFor(): void
    {
        $this->invoice(9, '000000009', (string)self::PREAUTH, 527.0);
        $this->record((string)self::PREAUTH);
        $this->qliro(self::PREAUTH, 'Preauthorization', 'Success', 527.0);
        $this->qliro(331743203, 'Capture', 'InProcess', 527.0);

        self::assertSame(InvoiceCaptureRelinker::PENDING, $this->relinker()->relink(1, false)[0]['outcome']);
        self::assertSame([], $this->saved);
    }

    /**
     * The capture a shipment's confirmation recorded, which no invoice carries, is taken as it is
     */
    public function testACaptureWithATransactionOfItsOwnKeepsIt(): void
    {
        $this->invoice(9, '000000009', (string)self::PREAUTH, 527.0);
        $this->record((string)self::PREAUTH);
        $this->record('331743203', ['captured_amount' => 527.0, 'captured_currency' => 'SEK']);
        $this->qliro(self::PREAUTH, 'Preauthorization', 'Success', 527.0);
        $this->qliro(331743203, 'Capture', 'Success', 527.0);

        self::assertSame(InvoiceCaptureRelinker::RELINKED, $this->relinker()->relink(1, false)[0]['outcome']);
        // The capture's own record, then the preauthorization's, left without an amount
        self::assertSame([$this->records['331743203'], $this->records[(string)self::PREAUTH]], $this->saved);
        self::assertSame('331743203', $this->updates[0][1]['transaction_id']);
        self::assertSame((string)self::PREAUTH, $this->records[(string)self::PREAUTH]->getTxnId());
    }

    /**
     * A refund splits by captured amount once any capture has one, so the invoice already on its
     * capture gets its amount as well, or its refund would find nothing left
     */
    public function testAnInvoiceOnACaptureGetsItsAmountWhenAnotherIsRelinked(): void
    {
        $this->invoice(8, '000000008', '331743203', 100.0);
        $this->record('331743203');
        $this->invoice(9, '000000009', (string)self::PREAUTH, 50.0);
        $this->record((string)self::PREAUTH);
        $this->qliro(self::PREAUTH, 'Preauthorization', 'Success', 150.0);
        $this->qliro(331743203, 'Capture', 'Success', 100.0);
        $this->qliro(331750001, 'Capture', 'Success', 50.0);

        $lines = $this->relinker()->relink(1, false);

        self::assertSame([InvoiceCaptureRelinker::TRACKED, InvoiceCaptureRelinker::RELINKED], array_column($lines, 'outcome'));
        self::assertSame(100.0, $this->records['331743203']->getAdditionalInformation('captured_amount'));
        self::assertSame(50.0, $this->records[(string)self::PREAUTH]->getAdditionalInformation('captured_amount'));
    }

    /**
     * What was refunded of which capture is not known, and a captured amount without it would let a
     * refund ask Qliro for more than is left
     */
    public function testAnOrderWithRefundsAtQliroIsLeftForAPerson(): void
    {
        $this->invoice(8, '000000008', '331743203', 100.0);
        $this->record('331743203');
        $this->invoice(9, '000000009', (string)self::PREAUTH, 50.0);
        $this->record((string)self::PREAUTH);
        $this->qliro(self::PREAUTH, 'Preauthorization', 'Success', 150.0);
        $this->qliro(331743203, 'Capture', 'Success', 100.0);
        $this->qliro(331750001, 'Capture', 'Success', 50.0);
        $this->qliro(331760000, 'UpdateInvoice', 'Success', -20.0);

        $lines = $this->relinker()->relink(1, false);

        self::assertSame([InvoiceCaptureRelinker::MANUAL, InvoiceCaptureRelinker::MANUAL], array_column($lines, 'outcome'));
        self::assertSame([], $this->saved);
    }

    public function testARefundQliroFailedDoesNotCount(): void
    {
        $this->invoice(9, '000000009', (string)self::PREAUTH, 50.0);
        $this->record((string)self::PREAUTH);
        $this->qliro(self::PREAUTH, 'Preauthorization', 'Success', 50.0);
        $this->qliro(331750001, 'Capture', 'Success', 50.0);
        $this->qliro(331760000, 'Refund', 'Error', 20.0);

        self::assertSame(InvoiceCaptureRelinker::RELINKED, $this->relinker()->relink(1, false)[0]['outcome']);
    }

    /**
     * A per unit discount is divided to the öre, so the capture may be an öre off its invoice
     */
    public function testACaptureAnÖreOffIsTaken(): void
    {
        $this->invoice(9, '000000009', (string)self::PREAUTH, 277.14);
        $this->record((string)self::PREAUTH);
        $this->qliro(self::PREAUTH, 'Preauthorization', 'Success', 424.80);
        $this->qliro(331743203, 'Capture', 'Success', 147.67);
        $this->qliro(331750001, 'Capture', 'Success', 277.13);

        self::assertSame(331750001, $this->relinker()->relink(1, false)[0]['to']);
    }

    public function testTheOnlyCaptureOfAnotherAmountIsNotTaken(): void
    {
        $this->invoice(9, '000000009', (string)self::PREAUTH, 300.0);
        $this->record((string)self::PREAUTH);
        $this->qliro(self::PREAUTH, 'Preauthorization', 'Success', 400.0);
        $this->qliro(331743203, 'Capture', 'Success', 100.0);

        self::assertSame(InvoiceCaptureRelinker::MANUAL, $this->relinker()->relink(1, false)[0]['outcome']);
    }

    /**
     * The order changes as a whole or not at all
     */
    public function testAFailedSaveRollsTheWholeOrderBack(): void
    {
        $first = $this->invoice(8, '000000008', (string)self::PREAUTH, 100.0);
        $second = $this->invoice(9, '000000009', (string)self::PREAUTH, 527.0);
        $this->record((string)self::PREAUTH);
        $this->qliro(self::PREAUTH, 'Preauthorization', 'Success', 627.0);
        $this->qliro(331743203, 'Capture', 'Success', 100.0);
        $this->qliro(331750001, 'Capture', 'Success', 527.0);

        $this->connection->expects(self::once())->method('rollBack');
        $this->connection->expects(self::never())->method('commit');
        $lines = $this->relinker(new \RuntimeException('deadlock'), failOnSave: 2)->relink(1, false);

        self::assertSame([InvoiceCaptureRelinker::FAILED, InvoiceCaptureRelinker::FAILED], array_column($lines, 'outcome'));
        self::assertSame('deadlock', $lines[1]['note']);
        self::assertSame((string)self::PREAUTH, $first->getTransactionId());
        self::assertSame((string)self::PREAUTH, $second->getTransactionId());
    }

    public function testANewCaptureHangsUnderTheAuthorization(): void
    {
        $this->invoice(9, '000000009', (string)self::PREAUTH, 527.0);
        $this->record('qliroone-4711', [], 'authorization');
        $this->qliro(self::PREAUTH, 'Preauthorization', 'Success', 527.0);
        $this->qliro(331743203, 'Capture', 'Success', 527.0);

        $this->relinker()->relink(1, false);

        self::assertSame('qliroone-4711', $this->saved[0]->getParentTxnId());
    }

    /**
     * A reconcile run applied the refusal's status row to the preauthorization and gave it its amount
     */
    public function testAPreauthorizationWithAnAmountIsStillMoved(): void
    {
        $this->invoice(9, '000000009', (string)self::PREAUTH, 527.0);
        $this->record((string)self::PREAUTH, ['captured_amount' => 600.0, 'refunded_amount' => 10.0]);
        $this->qliro(self::PREAUTH, 'Preauthorization', 'Success', 600.0);
        $this->qliro(331743203, 'Capture', 'Success', 527.0);

        self::assertSame(InvoiceCaptureRelinker::RELINKED, $this->relinker()->relink(1, false)[0]['outcome']);
        self::assertSame(527.0, $this->saved[0]->getAdditionalInformation('captured_amount'));
        self::assertNull($this->saved[0]->getAdditionalInformation('refunded_amount'));
    }

    /**
     * Left Created, the refusal's status row would have a reconcile run apply the preauthorization
     */
    public function testTheRefusalStatusRowIsSettled(): void
    {
        $this->invoice(9, '000000009', (string)self::PREAUTH, 527.0);
        $this->record((string)self::PREAUTH);
        $this->qliro(self::PREAUTH, 'Preauthorization', 'Success', 527.0);
        $this->qliro(331743203, 'Capture', 'Success', 527.0);

        $this->relinker()->relink(1, false);

        self::assertSame('qliroone_om_status', $this->updates[1][0]);
        self::assertSame(['transaction_status' => 'Success'], $this->updates[1][1]);
        self::assertSame(self::PREAUTH, $this->updates[1][2]['transaction_id = ?']);
        self::assertSame(4711, $this->updates[1][2]['qliro_order_id = ?']);
        self::assertSame('Created', $this->updates[1][2]['transaction_status = ?']);
    }

    /**
     * A refund splits over the captures with an amount, so one no invoice carries could not be reached
     */
    public function testACaptureNoInvoiceCarriesHoldsTheOrder(): void
    {
        $this->invoice(9, '000000009', (string)self::PREAUTH, 527.0);
        $this->record((string)self::PREAUTH);
        $this->qliro(self::PREAUTH, 'Preauthorization', 'Success', 727.0);
        $this->qliro(331743203, 'Capture', 'Success', 527.0);
        $this->qliro(331750001, 'Capture', 'Success', 200.0);

        $lines = $this->relinker()->relink(1, false);

        self::assertSame(InvoiceCaptureRelinker::MANUAL, $lines[0]['outcome']);
        self::assertStringContainsString('capture 331750001 that no invoice carries', $lines[0]['note']);
        self::assertSame([], $this->saved);
    }

    public function testTwoInvoicesOnOneCaptureWithoutATransactionGetOne(): void
    {
        $this->invoice(8, '000000008', '331743203', 100.0);
        $this->invoice(9, '000000009', '331743203', 100.0);
        $this->qliro(331743203, 'Capture', 'Success', 200.0);

        $lines = $this->relinker()->relink(1, false);

        self::assertSame([InvoiceCaptureRelinker::TRACKED, InvoiceCaptureRelinker::TRACKED], array_column($lines, 'outcome'));
        self::assertCount(1, $this->saved);
    }

    /**
     * Its refund would be drawn from the captures once they have amounts
     */
    public function testAnInvoiceWithoutAQliroCaptureHoldsTheOrder(): void
    {
        $this->invoice(8, '000000008', 'qliroone-4711-capture', 100.0);
        $this->invoice(9, '000000009', (string)self::PREAUTH, 527.0);
        $this->invoice(10, '000000010', '', 0.0);
        $this->record((string)self::PREAUTH);
        $this->qliro(self::PREAUTH, 'Preauthorization', 'Success', 627.0);
        $this->qliro(331743203, 'Capture', 'Success', 527.0);

        $lines = $this->relinker()->relink(1, false);

        self::assertSame([InvoiceCaptureRelinker::MANUAL, InvoiceCaptureRelinker::MANUAL], array_column($lines, 'outcome'));
        self::assertSame([], $this->saved);
    }

    public function testAnAdditionToTheInvoiceIsNoRefund(): void
    {
        $this->invoice(9, '000000009', (string)self::PREAUTH, 527.0);
        $this->record((string)self::PREAUTH);
        $this->qliro(self::PREAUTH, 'Preauthorization', 'Success', 527.0);
        $this->qliro(331743203, 'Capture', 'Success', 527.0);
        $this->qliro(331760000, 'UpdateInvoice', 'Success', 29.0);

        self::assertSame(InvoiceCaptureRelinker::RELINKED, $this->relinker()->relink(1, false)[0]['outcome']);
    }

    /**
     * Left as a capture with an amount, the preauthorization would take part of a later refund
     */
    public function testThePreauthorizationLeftBehindLosesItsAmount(): void
    {
        $this->invoice(9, '000000009', (string)self::PREAUTH, 527.0);
        $this->record((string)self::PREAUTH, ['captured_amount' => 600.0, 'provider_transaction_id' => 'psp-1']);
        $this->record('331743203', ['captured_amount' => 527.0]);
        $this->qliro(self::PREAUTH, 'Preauthorization', 'Success', 600.0);
        $this->qliro(331743203, 'Capture', 'Success', 527.0);

        self::assertSame(InvoiceCaptureRelinker::RELINKED, $this->relinker()->relink(1, false)[0]['outcome']);
        $preauthorization = $this->records[(string)self::PREAUTH];
        self::assertContains($preauthorization, $this->saved);
        self::assertNull($preauthorization->getAdditionalInformation('captured_amount'));
        self::assertNull($preauthorization->getAdditionalInformation('provider_transaction_id'));
    }

    // ---- harness ------------------------------------------------------------------------------

    private function relinker(?\Throwable $saveFails = null, int $failOnSave = 1): InvoiceCaptureRelinker
    {
        $payment = $this->getMockBuilder(Payment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $payment->setId(5);

        $collection = $this->createMock(InvoiceCollection::class);
        $collection->method('getItems')->willReturnCallback(fn () => $this->invoices);
        $collection->method('getIterator')->willReturnCallback(fn () => new \ArrayIterator(array_values($this->invoices)));

        $order = $this->createMock(Order::class);
        $order->method('load')->with(1)->willReturnSelf();
        $order->method('getId')->willReturn(1);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getInvoiceCollection')->willReturn($collection);
        $order->method('getShipmentsCollection')->willReturn([]);
        $order->method('addCommentToStatusHistory')
            ->willReturn($this->createMock(OrderStatusHistoryInterface::class));

        $orders = $this->createMock(OrderFactory::class);
        $orders->method('create')->willReturn($order);

        $link = $this->createMock(LinkInterface::class);
        $link->method('getQliroOrderId')->willReturn(4711);
        $links = $this->createMock(LinkRepositoryInterface::class);
        $links->method('getByOrderId')->with(1, false)->willReturn($link);

        $api = $this->createMock(OrderManagement::class);
        $api->method('getOrder')->willReturnCallback(
            fn () => (new AdminOrder())->setPaymentTransactions($this->atQliro)
        );

        $transactions = $this->createMock(TransactionCollection::class);
        $transactions->method('addPaymentIdFilter')->with(5)->willReturnSelf();
        $transactions->method('getItems')->willReturnCallback(fn () => array_values($this->records));
        $collections = $this->createMock(TransactionCollectionFactory::class);
        $collections->method('create')->willReturn($transactions);

        $factory = $this->createMock(TransactionFactory::class);
        $factory->method('create')->willReturnCallback(
            fn () => $this->getMockBuilder(Transaction::class)->disableOriginalConstructor()->onlyMethods([])->getMock()
        );

        $saves = 0;
        $resource = $this->createMock(TransactionResource::class);
        $resource->method('save')->willReturnCallback(function ($record) use ($saveFails, $failOnSave, &$saves, $resource) {
            if ($saveFails && ++$saves === $failOnSave) {
                throw $saveFails;
            }
            $this->saved[] = $record;
            return $resource;
        });

        $history = $this->createMock(OrderStatusHistoryRepositoryInterface::class);
        $history->method('save')->willReturnCallback(function ($comment) {
            $this->comments++;
            return $comment;
        });

        $connection = $this->createMock(ResourceConnection::class);
        $connection->method('getConnection')->willReturn($this->connection);
        $connection->method('getTableName')->willReturnArgument(0);

        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('create')->willReturn($this->createMock(SearchCriteria::class));

        return new InvoiceCaptureRelinker(
            $orders,
            $links,
            $api,
            new CaptureCoverage($this->createMock(OrderManagementStatusRepositoryInterface::class), $builder),
            $collections,
            $factory,
            $resource,
            $history,
            $connection,
            $this->createMock(LogManager::class)
        );
    }

    private function invoice(int $id, string $incrementId, string $transactionId, float $grandTotal): Invoice
    {
        $invoice = $this->getMockBuilder(Invoice::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $invoice->setData([
            'entity_id' => $id,
            'id' => $id,
            'increment_id' => $incrementId,
            'transaction_id' => $transactionId,
            'grand_total' => $grandTotal,
            'state' => Invoice::STATE_PAID,
        ]);
        $this->invoices[$id] = $invoice;

        return $invoice;
    }

    private function record(string $txnId, array $info = [], string $type = TransactionInterface::TYPE_CAPTURE): void
    {
        $record = $this->getMockBuilder(Transaction::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $record->setData([
            'transaction_id' => count($this->records) + 1,
            'txn_id' => $txnId,
            'txn_type' => $type,
            'parent_txn_id' => $type === TransactionInterface::TYPE_CAPTURE ? 'qliroone-4711' : null,
        ]);

        foreach ($info as $key => $value) {
            $record->setAdditionalInformation($key, $value);
        }

        $this->records[$txnId] = $record;
    }

    private function qliro(int $id, string $type, string $status, float $amount): void
    {
        $this->atQliro[] = (new OrderPaymentTransaction())
            ->setPaymentTransactionId($id)
            ->setType($type)
            ->setStatus($status)
            ->setAmount($amount)
            ->setCurrency('SEK');
    }
}
