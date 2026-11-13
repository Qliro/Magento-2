<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Admin;

use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\AdminAddItemsToInvoiceRequestInterface;
use Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterfaceFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Model\Link;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\OrderManagementStatus;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\AddItemsToInvoiceBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\CaptureVatLedger;
use Qliro\QliroOne\Model\QliroOrder\Admin\SequentialRefundProcessor;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Admin\SequentialRefundProcessor
 */
class SequentialRefundProcessorTest extends TestCase
{
    /**
     * The callback that confirms a refund only names its transaction, so the split it went out
     * with is noted under that transaction when Qliro takes it
     */
    public function testNotesTheSplitOfTheRefundQliroTookUnderItsTransaction(): void
    {
        $payment = $this->payment();
        $split = [['vat_rate' => 6.0, 'amount' => 50.0]];

        $ledger = $this->createMock(CaptureVatLedger::class);
        $ledger->expects(self::once())->method('recordSent')->with($payment, '4001', '3001', $split);

        $this->processor($this->answer('Created', 4001), $ledger)->start($payment, [
            ['payment_transaction_id' => 3001, 'amount' => 50.0, 'vat_split' => $split],
        ]);
    }

    public function testNotesNothingForARefundQliroDidNotTake(): void
    {
        $ledger = $this->createMock(CaptureVatLedger::class);
        $ledger->expects(self::never())->method('recordSent');

        $this->expectExceptionMessage('Unable to refund');

        $this->processor($this->answer('Error', 4001), $ledger)->start($this->payment(), [
            ['payment_transaction_id' => 3001, 'amount' => 50.0, 'vat_split' => [['vat_rate' => 6.0, 'amount' => 50.0]]],
        ]);
    }

    /**
     * A queued refund is sent from a callback, where nothing else saves the payment after it
     */
    public function testSavesThePaymentAgainOnceAQueuedRefundIsNoted(): void
    {
        $payment = $this->payment();
        $payment->setAdditionalInformation(SequentialRefundProcessor::KEY_PENDING_QUEUE, [
            ['payment_transaction_id' => 3002, 'amount' => 20.0, 'vat_split' => [['vat_rate' => 25.0, 'amount' => 20.0]]],
        ]);

        $saved = [];
        $repository = $this->createMock(OrderPaymentRepositoryInterface::class);
        $repository->method('save')->willReturnCallback(function ($saving) use (&$saved, $payment) {
            $saved[] = $saving->getAdditionalInformation(CaptureVatLedger::KEY)['sent'] ?? [];

            return $payment;
        });

        $this->processor($this->answer('Created', 4002), new CaptureVatLedger(), $repository)->continueQueue($payment);

        self::assertSame([[], ['4002' => ['capture' => '3002', 'split' => [['vat_rate' => 25.0, 'amount' => 20.0]]]]], $saved);
    }

    private function processor(
        AdminTransactionResponseInterface $answer,
        CaptureVatLedger $ledger,
        ?OrderPaymentRepositoryInterface $repository = null
    ): SequentialRefundProcessor {
        $api = $this->createMock(OrderManagementInterface::class);
        $api->method('addItemsToInvoice')->willReturn([$answer]);

        $builder = $this->createMock(AddItemsToInvoiceBuilder::class);
        $builder->method('setPayment')->willReturnSelf();
        $builder->method('setAllocation')->willReturnSelf();
        $builder->method('create')->willReturn($this->createMock(AdminAddItemsToInvoiceRequestInterface::class));

        $link = $this->createMock(Link::class);
        $link->method('getQliroOrderId')->willReturn(998877);
        $links = $this->createMock(LinkRepositoryInterface::class);
        $links->method('getByOrderId')->willReturn($link);

        $statusFactory = $this->createMock(OrderManagementStatusInterfaceFactory::class);
        $statusFactory->method('create')->willReturn($this->createMock(OrderManagementStatus::class));

        return new SequentialRefundProcessor(
            $api,
            $builder,
            $statusFactory,
            $this->createMock(OrderManagementStatusRepositoryInterface::class),
            $links,
            $repository ?? $this->createMock(OrderPaymentRepositoryInterface::class),
            $this->createMock(LogManager::class),
            $ledger
        );
    }

    private function answer(string $status, int $transactionId): AdminTransactionResponseInterface
    {
        $answer = $this->createMock(AdminTransactionResponseInterface::class);
        $answer->method('getStatus')->willReturn($status);
        $answer->method('getPaymentTransactionId')->willReturn($transactionId);

        return $answer;
    }

    private function payment(): Payment
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(5);
        $order->method('getStoreId')->willReturn(1);

        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrder'])
            ->getMock();
        $payment->method('getOrder')->willReturn($order);

        return $payment;
    }
}
