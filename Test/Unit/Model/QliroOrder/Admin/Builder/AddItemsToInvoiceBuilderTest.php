<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Admin\Builder;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Creditmemo\Item as CreditmemoItem;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\AdminAddItemsToInvoiceRequestInterfaceFactory;
use Qliro\QliroOne\Api\Data\AdminAdditionsInterfaceFactory;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Magento\Framework\DataObject\IdentityService;
use Qliro\QliroOne\Model\Api\RequestId;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\QliroOrder\Admin\AddItemsToInvoiceRequest;
use Qliro\QliroOne\Model\QliroOrder\Admin\AdminAdditions;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\AddItemsToInvoiceBuilder;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\LineVatRate;

/**
 * The refund a merchant sends to Qliro, as a negative line against a capture. The amount here is
 * what the buyer is paid back, so it is pinned together with the rate the two amounts imply.
 *
 * @see \Qliro\QliroOne\Model\QliroOrder\Admin\Builder\AddItemsToInvoiceBuilder
 */
class AddItemsToInvoiceBuilderTest extends TestCase
{
    private const QLIRO_ORDER_ID = 998877;

    /**
     * A refund is one negative line against the capture the payment was taken on, at the credit
     * memo's own total and the rate its lines are taxed with.
     */
    public function testSendsTheRefundAsANegativeLineAgainstTheCapture(): void
    {
        $payment = $this->payment($this->creditMemo(125.0, $this->refundedLines([25.0])), 4711);

        $request = $this->builder()->setPayment($payment)->create();

        self::assertSame(self::QLIRO_ORDER_ID, $request->getOrderId());
        self::assertSame('SEK', $request->getCurrency());
        self::assertCount(1, $request->getAdditions());
        self::assertSame(4711, $request->getAdditions()[0]->getPaymentTransactionId());

        $line = $request->getAdditions()[0]->getOrderItems()[0];
        self::assertSame(QliroOrderItemInterface::TYPE_DISCOUNT, $line->getType());
        self::assertSame('Refund', $line->getMerchantReference());
        self::assertSame(1.0, $line->getQuantity());
        self::assertSame(-125.0, $line->getPricePerItemIncVat());
        self::assertSame(-100.0, $line->getPricePerItemExVat());
        self::assertSame(25.0, $line->getVatRate());
    }

    /**
     * A credit memo whose lines are taxed at more than one rate states no rate at all: a single
     * line cannot describe two, and a wrong one would refund the wrong VAT.
     */
    public function testStatesNoRateWhenTheCreditMemoMixesRates(): void
    {
        $payment = $this->payment($this->creditMemo(125.0, $this->refundedLines([25.0, 12.0])), 4711);

        $line = $this->builder()->setPayment($payment)->create()->getAdditions()[0]->getOrderItems()[0];

        self::assertSame(0.0, $line->getVatRate());
        self::assertSame(-125.0, $line->getPricePerItemIncVat());
        self::assertSame(-125.0, $line->getPricePerItemExVat());
    }

    /**
     * A line refunded at zero quantity, and a line that is only a placeholder for its parent,
     * say nothing about the rate: they are what a bundle and its children look like on a memo.
     */
    public function testReadsTheRateFromTheLinesThatWereActuallyRefunded(): void
    {
        $creditMemo = $this->creditMemo(125.0, [
            $this->creditMemoItem(12.0, 0.0, false),
            $this->creditMemoItem(6.0, 1.0, true),
            $this->creditMemoItem(25.0, 1.0, false),
        ]);

        $line = $this->builder()->setPayment($this->payment($creditMemo, 4711))
            ->create()->getAdditions()[0]->getOrderItems()[0];

        self::assertSame(25.0, $line->getVatRate());
    }

    /**
     * Qliro checks every addition against what is left in its own capture, so a refund larger
     * than one capture is spread over several, each addition carrying its own share and the rate
     * that was captured with it.
     */
    public function testSpreadsARefundOverTheCapturesItWasAllocatedTo(): void
    {
        $payment = $this->payment($this->creditMemo(300.0, $this->refundedLines([25.0])), 4711);

        $request = $this->builder()->setPayment($payment)->setAllocation([
            ['payment_transaction_id' => 11, 'amount' => 200.0, 'vat_rate' => 25.0],
            ['payment_transaction_id' => 12, 'amount' => 100.0, 'vat_rate' => 12.0],
        ])->create();

        self::assertCount(2, $request->getAdditions());
        self::assertSame(11, $request->getAdditions()[0]->getPaymentTransactionId());
        self::assertSame(-200.0, $request->getAdditions()[0]->getOrderItems()[0]->getPricePerItemIncVat());
        self::assertSame(-160.0, $request->getAdditions()[0]->getOrderItems()[0]->getPricePerItemExVat());
        self::assertSame(12, $request->getAdditions()[1]->getPaymentTransactionId());
        self::assertSame(-100.0, $request->getAdditions()[1]->getOrderItems()[0]->getPricePerItemIncVat());
        self::assertSame(-89.29, $request->getAdditions()[1]->getOrderItems()[0]->getPricePerItemExVat());
        self::assertSame(12.0, $request->getAdditions()[1]->getOrderItems()[0]->getVatRate());
    }

    /**
     * An allocation entry that carries no rate of its own is described by the credit memo in
     * context, which is the refund the allocation was queued for.
     */
    public function testFallsBackToTheCreditMemoRateForAnAllocationWithoutOne(): void
    {
        $payment = $this->payment($this->creditMemo(125.0, $this->refundedLines([25.0])), 4711);

        $request = $this->builder()->setPayment($payment)
            ->setAllocation([['payment_transaction_id' => 11, 'amount' => 125.0]])
            ->create();

        self::assertSame(25.0, $request->getAdditions()[0]->getOrderItems()[0]->getVatRate());
        self::assertSame(-100.0, $request->getAdditions()[0]->getOrderItems()[0]->getPricePerItemExVat());
    }

    /**
     * The refund goes out as a refund whichever sign the caller holds the amount in.
     */
    public function testSendsTheAmountAsARefundWhicheverSignItArrivesIn(): void
    {
        $payment = $this->payment($this->creditMemo(-125.0, $this->refundedLines([25.0])), 4711);

        $line = $this->builder()->setPayment($payment)->create()->getAdditions()[0]->getOrderItems()[0];

        self::assertSame(-125.0, $line->getPricePerItemIncVat());
    }

    /**
     * The rate a refund is queued with is read from the same credit memo the line would be, so a
     * queued allocation and the line it becomes cannot describe the refund differently.
     */
    public function testAnswersTheRateARefundShouldBeQueuedWith(): void
    {
        $payment = $this->payment($this->creditMemo(125.0, $this->refundedLines([25.0])), 4711);

        self::assertSame(25.0, $this->builder()->getRefundVatRate($payment));
    }

    /**
     * An order with no Qliro order behind it is logged rather than thrown out of the refund. What
     * comes back is a request nothing may read: its fields are typed and none was ever set, so
     * every getter raises an Error. Only the logging is pinned here because no caller reaches
     * that request, `Management\\Payment` and `SequentialRefundProcessor` both resolve the same
     * link before the builder and fail there.
     */
    public function testLogsAnOrderWithNoQliroOrderBehindIt(): void
    {
        $linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $linkRepository->method('getByOrderId')->willThrowException(new NoSuchEntityException());

        $logManager = $this->createMock(Manager::class);
        $logManager->expects(self::once())->method('debug');

        $this->builder($linkRepository, $logManager)
            ->setPayment($this->payment($this->creditMemo(125.0, $this->refundedLines([25.0])), 4711))
            ->create();
    }

    /**
     * The builder is one shared instance, so it releases the payment and the allocation it built
     * from: the next refund must not be sent against the capture of the one before it.
     */
    public function testReleasesThePaymentItBuiltFrom(): void
    {
        $builder = $this->builder();
        $builder->setPayment($this->payment($this->creditMemo(125.0, $this->refundedLines([25.0])), 4711))->create();

        $this->expectException(\LogicException::class);
        $builder->create();
    }

    public function testRefusesToBuildWithoutAPayment(): void
    {
        $this->expectException(\LogicException::class);

        $this->builder()->create();
    }

    private function builder(
        ?LinkRepositoryInterface $linkRepository = null,
        ?Manager $logManager = null
    ): AddItemsToInvoiceBuilder {
        $requestFactory = $this->createMock(AdminAddItemsToInvoiceRequestInterfaceFactory::class);
        $requestFactory->method('create')
            ->willReturnCallback(static fn(): AddItemsToInvoiceRequest => new AddItemsToInvoiceRequest());

        $additionsFactory = $this->createMock(AdminAdditionsInterfaceFactory::class);
        $additionsFactory->method('create')->willReturnCallback(static fn(): AdminAdditions => new AdminAdditions());

        $itemFactory = $this->createMock(QliroOrderItemInterfaceFactory::class);
        $itemFactory->method('create')->willReturnCallback(static fn(): Item => new Item());

        $config = $this->createMock(Config::class);
        $config->method('getMerchantApiKey')->willReturn('merchant-key');

        return new AddItemsToInvoiceBuilder(
            $requestFactory,
            $linkRepository ?? $this->linkRepository(),
            $logManager ?? $this->createMock(Manager::class),
            $config,
            $additionsFactory,
            $itemFactory,
            new LineVatRate(),
            new RequestId(new IdentityService())
        );
    }

    private function linkRepository(): LinkRepositoryInterface&MockObject
    {
        $link = $this->createMock(LinkInterface::class);
        $link->method('getQliroOrderId')->willReturn(self::QLIRO_ORDER_ID);

        $linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $linkRepository->method('getByOrderId')->willReturn($link);

        return $linkRepository;
    }

    private function payment(
        Creditmemo $creditMemo,
        int $parentTransactionId,
        float $alreadyRefunded = 0.0
    ): Payment&MockObject {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(5);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getOrderCurrencyCode')->willReturn('SEK');
        $order->method('getIncrementId')->willReturn('000000123');
        $order->method('getTotalRefunded')->willReturn($alreadyRefunded);

        $payment = $this->createMock(Payment::class);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getCreditmemo')->willReturn($creditMemo);
        $payment->method('getParentTransactionId')->willReturn($parentTransactionId);

        return $payment;
    }

    /**
     * @param CreditmemoItem[] $items
     */
    private function creditMemo(float $grandTotal, array $items): Creditmemo&MockObject
    {
        $creditMemo = $this->createMock(Creditmemo::class);
        $creditMemo->method('getGrandTotal')->willReturn($grandTotal);
        $creditMemo->method('getAllItems')->willReturn($items);

        return $creditMemo;
    }

    /**
     * One refunded line per rate
     *
     * @param float[] $taxPercents
     * @return CreditmemoItem[]
     */
    private function refundedLines(array $taxPercents): array
    {
        return array_map(
            fn(float $taxPercent): CreditmemoItem => $this->creditMemoItem($taxPercent, 1.0, false),
            $taxPercents
        );
    }

    private function creditMemoItem(float $taxPercent, float $qty, bool $isDummy): CreditmemoItem&MockObject
    {
        $orderItem = $this->createMock(OrderItem::class);
        $orderItem->method('isDummy')->willReturn($isDummy);
        $orderItem->method('getTaxPercent')->willReturn($taxPercent);

        $item = $this->createMock(CreditmemoItem::class);
        $item->method('getOrderItem')->willReturn($orderItem);
        $item->method('getQty')->willReturn($qty);

        return $item;
    }

    /**
     * A refund that timed out after Qliro booked it leaves no credit memo behind, Magento rolls
     * it back and the merchant refunds again. Qliro books a repeated RequestId once, so the same
     * refund asked for twice has to carry the same one.
     */
    public function testTheSameRefundCarriesTheSameRequestId(): void
    {
        $first = $this->builder()
            ->setPayment($this->payment($this->creditMemo(125.0, $this->refundedLines([25.0])), 4711))
            ->create();
        $second = $this->builder()
            ->setPayment($this->payment($this->creditMemo(125.0, $this->refundedLines([25.0])), 4711))
            ->create();

        self::assertNotSame('', $first->getRequestId());
        self::assertSame($first->getRequestId(), $second->getRequestId());
    }

    /**
     * A second refund the merchant really means differs in what the order has already given back,
     * so it is a request of its own and Qliro books it.
     */
    public function testARefundOnTopOfAnotherCarriesItsOwnRequestId(): void
    {
        $first = $this->builder()
            ->setPayment($this->payment($this->creditMemo(125.0, $this->refundedLines([25.0])), 4711))
            ->create();
        $second = $this->builder()
            ->setPayment($this->payment($this->creditMemo(125.0, $this->refundedLines([25.0])), 4711, 125.0))
            ->create();

        self::assertNotSame($first->getRequestId(), $second->getRequestId());
    }

    /**
     * The entries of one credit memo go out as separate calls, one per capture, so each needs an
     * id of its own or Qliro would take the second for a repeat of the first.
     */
    public function testEachAllocationEntryCarriesItsOwnRequestId(): void
    {
        $payment = $this->payment($this->creditMemo(125.0, $this->refundedLines([25.0])), 4711);

        $first = $this->builder()->setPayment($payment)
            ->setAllocation([['payment_transaction_id' => 4711, 'amount' => 100.0]])
            ->create();
        $second = $this->builder()->setPayment($payment)
            ->setAllocation([['payment_transaction_id' => 4712, 'amount' => 25.0]])
            ->create();

        self::assertNotSame($first->getRequestId(), $second->getRequestId());
    }
}
