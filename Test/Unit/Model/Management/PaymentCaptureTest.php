<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Management;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use Magento\Sales\Model\Order\Shipment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\AdminMarkItemsAsShippedRequestInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterfaceFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Model\Api\Client\Exception\OrderManagementApiException;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Link;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\AbstractManagement;
use Qliro\QliroOne\Model\OrderManagementStatus;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\AddItemsToInvoiceBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\InvoiceMarkItemsAsShippedRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\ShipmentMarkItemsAsShippedRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\CaptureRefundAllocator;
use Qliro\QliroOne\Model\QliroOrder\Admin\SequentialRefundProcessor;

/**
 * The capture submission contract, per PLIN-381. Both paths are covered, because both duplicate the
 * logic and only the invoice one carries the transaction id onto the payment.
 *
 * @see \Qliro\QliroOne\Model\Management\Payment::captureByShipment
 * @see \Qliro\QliroOne\Model\Management\Payment::captureByInvoice
 */
class PaymentCaptureTest extends TestCase
{
    private const QLIRO_ORDER_ID = 5478412;

    private OrderManagementInterface&MockObject $orderManagementApi;
    private Order&MockObject $order;

    /** @var array<int, array{transactionId: int|string|null, message: string}> */
    private array $savedStatuses = [];

    /** @var array<int, string> */
    private array $orderComments = [];

    private int|string|null $appliedTransactionId = null;

    /** @var array<int, \ArrayObject> */
    private array $builtRows = [];

    protected function setUp(): void
    {
        $this->savedStatuses = [];
        $this->orderComments = [];
        $this->appliedTransactionId = null;
        $this->builtRows = [];
    }

    // ---- captureByShipment --------------------------------------------------------------------

    /**
     * capture_on_shipment and capture_on_invoice are independent settings and one admin action can
     * fire both paths. The second submission is refused by Qliro as already shipped, and that
     * refusal used to roll the whole action back: money captured, no invoice, order unclosable.
     */
    public function testASecondCaptureInTheSameRequestIsNotSubmitted(): void
    {
        $capture = $this->buildCapture();
        $this->order->setData(AbstractManagement::QLIRO_CAPTURE_SUBMITTED, true);

        $this->orderManagementApi->expects(self::never())->method('markItemsAsShipped');

        $capture->captureByShipment($this->buildShipment());
    }

    /**
     * The first submission marks the order and records Qliro's transaction id on it, which is what
     * lets the path that stands down still put that id on the payment.
     */
    public function testTheFirstCaptureRecordsTheTransactionOnTheOrder(): void
    {
        $capture = $this->buildCapture();
        $this->orderManagementApi->method('markItemsAsShipped')->willReturn($this->buildResult('Created', 325188256));

        $capture->captureByShipment($this->buildShipment());

        self::assertTrue((bool)$this->order->getData(AbstractManagement::QLIRO_CAPTURE_SUBMITTED));
        self::assertSame(325188256, $this->order->getData(AbstractManagement::QLIRO_CAPTURE_TRANSACTION_ID));
    }

    /**
     * Already shipped means the money moved, so the document must be allowed to complete. Throwing
     * left the order permanently unclosable: the reservation is spent, so every retry is refused
     * the same way. Accepting it silently is its own defect though, so the OM status row is
     * asserted rather than merely the absence of a throw.
     */
    public function testAnAlreadyShippedRefusalIsAcceptedAndRecorded(): void
    {
        $capture = $this->buildCapture();
        $this->refuseWith(
            AbstractManagement::QLIRO_ERROR_NO_ITEMS_LEFT,
            "All items already shipped for transaction '325065091' , therefore no items left."
        );

        $capture->captureByShipment($this->buildShipment());

        self::assertSame(325065091, $this->order->getData(AbstractManagement::QLIRO_CAPTURE_TRANSACTION_ID));
        self::assertCount(1, $this->savedStatuses, 'the capture has to stay on our books');
        self::assertSame(325065091, $this->savedStatuses[0]['transactionId']);
        self::assertStringContainsString('already registered at Qliro', $this->savedStatuses[0]['message']);
        self::assertSame([], $this->orderComments, 'the id was known, nothing to escalate');
    }

    /**
     * Qliro names the transaction in the refusal, which is the only place the id exists when the
     * capture happened in an earlier request. When the wording does not match we must not invent
     * one, and the miss has to reach an operator now rather than at refund time.
     */
    public function testAnUnidentifiableCaptureIsEscalatedOnTheOrder(): void
    {
        $capture = $this->buildCapture();
        $this->refuseWith(AbstractManagement::QLIRO_ERROR_NO_ITEMS_LEFT, 'Nothing left to ship');

        $capture->captureByShipment($this->buildShipment());

        self::assertNull($this->order->getData(AbstractManagement::QLIRO_CAPTURE_TRANSACTION_ID));
        self::assertCount(1, $this->orderComments);
        self::assertStringContainsString('could not be identified', $this->orderComments[0]);
    }

    /**
     * An order Qliro has never heard of is not transient, and "Request to Qliro One has failed"
     * told the operator nothing. The message now names the id and says retrying is futile.
     */
    public function testAnUnknownOrderIsReportedWithItsId(): void
    {
        $capture = $this->buildCapture();
        $this->refuseWith(AbstractManagement::QLIRO_ERROR_ORDER_NOT_FOUND, 'Order not found');

        try {
            $capture->captureByShipment($this->buildShipment());
            self::fail('an unknown order must fail the capture');
        } catch (LocalizedException $e) {
            self::assertStringContainsString((string)self::QLIRO_ORDER_ID, $e->getMessage());
            self::assertStringContainsString('Retrying will not help', $e->getMessage());
        }
    }

    /**
     * Any other refusal keeps its own reason rather than being relabelled.
     */
    public function testAnyOtherRefusalIsPassedThrough(): void
    {
        $capture = $this->buildCapture();
        $this->refuseWith('INVALID_ITEM', 'Item does not match');

        $this->expectException(OrderManagementApiException::class);

        $capture->captureByShipment($this->buildShipment());
    }

    // ---- captureByInvoice: the path that also has to put the id on the payment -----------------

    /**
     * The reason the id is carried on the order at all: when the sibling path captured in this
     * request, the invoice must record the capture under Qliro's transaction id. Letting Magento
     * generate its own leaves captured_amount unwritten, and CaptureRefundAllocator::getCaptures()
     * skips a capture without it, so the order becomes unrefundable.
     */
    public function testStandingDownStillPutsTheSiblingsTransactionIdOnThePayment(): void
    {
        $capture = $this->buildCapture();
        $this->order->setData(AbstractManagement::QLIRO_CAPTURE_SUBMITTED, true);
        $this->order->setData(AbstractManagement::QLIRO_CAPTURE_TRANSACTION_ID, 325065091);

        $this->orderManagementApi->expects(self::never())->method('markItemsAsShipped');

        $capture->captureByInvoice($this->buildPayment(), 100.0);

        self::assertSame(325065091, $this->appliedTransactionId);
    }

    /**
     * Same requirement when the capture happened in an EARLIER request, which is the realistic
     * flow: the shipment captures, then the merchant invoices manually before Qliro's callback
     * creates the invoice. There the order carries no marker, so the id comes from the refusal.
     */
    public function testAnAlreadyShippedInvoiceAdoptsTheTransactionFromTheRefusal(): void
    {
        $capture = $this->buildCapture();
        $this->refuseWith(
            AbstractManagement::QLIRO_ERROR_NO_ITEMS_LEFT,
            "All items already shipped for transaction '324964039' , therefore no items left."
        );

        $capture->captureByInvoice($this->buildPayment(), 100.0);

        self::assertSame(324964039, $this->appliedTransactionId);
        self::assertCount(1, $this->savedStatuses);
        self::assertSame([], $this->orderComments);
    }

    /**
     * When it cannot be identified the invoice is still allowed through, with nothing put on the
     * payment and the miss on the order for an operator to see.
     */
    public function testAnUnidentifiableInvoiceCaptureSetsNoTransactionId(): void
    {
        $capture = $this->buildCapture();
        $this->refuseWith(AbstractManagement::QLIRO_ERROR_NO_ITEMS_LEFT, 'Nothing left to ship');

        $capture->captureByInvoice($this->buildPayment(), 100.0);

        self::assertNull($this->appliedTransactionId);
        self::assertCount(1, $this->orderComments);
    }

    /**
     * A capture that goes through keeps recording Qliro's transaction id, on the payment and on the
     * order for the sibling path.
     */
    public function testASuccessfulInvoiceCaptureRecordsTheTransactionId(): void
    {
        $capture = $this->buildCapture();
        $this->orderManagementApi->method('markItemsAsShipped')->willReturn($this->buildResult('Created', 325188256));

        $capture->captureByInvoice($this->buildPayment(), 100.0);

        self::assertSame(325188256, $this->appliedTransactionId);
        self::assertSame(325188256, $this->order->getData(AbstractManagement::QLIRO_CAPTURE_TRANSACTION_ID));
    }

    // ---- harness ------------------------------------------------------------------------------

    /**
     * Make every markItemsAsShipped call answer with a Qliro refusal carrying this code.
     */
    private function refuseWith(string $code, string $message): void
    {
        $this->orderManagementApi->method('markItemsAsShipped')
            ->willThrowException($this->qliroRefusal($code, $message));
    }

    /**
     * Shaped the way Service and the OM client produce a refusal, so the code travels on the
     * TerminalException the caller reads it from.
     */
    private function qliroRefusal(string $code, string $message): OrderManagementApiException
    {
        $terminal = new TerminalException($message);
        $terminal->setQliroError($code, $message);

        return new OrderManagementApiException(__('Error [%1]: %2', $code, $message), $terminal);
    }

    private function buildResult(string $status, int $transactionId): object
    {
        return new class ($status, $transactionId) {
            public function __construct(private string $status, private int $transactionId)
            {
            }

            public function getStatus(): string
            {
                return $this->status;
            }

            public function getPaymentTransactionId(): int
            {
                return $this->transactionId;
            }
        };
    }

    private function buildShipment(): Shipment&MockObject
    {
        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getOrder')->willReturn($this->order);
        $shipment->method('getStoreId')->willReturn(1);
        $shipment->method('getId')->willReturn(7);

        return $shipment;
    }

    /**
     * captureByInvoice takes the payment, and setTransactionId on it is the side effect that keeps
     * refunds working, so it is recorded rather than ignored.
     */
    private function buildPayment(): Payment&MockObject
    {
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getOrder', 'getData', 'setTransactionId', 'getId'])
            ->getMock();
        $payment->method('getOrder')->willReturn($this->order);
        $payment->method('getData')->willReturn(null);
        $payment->method('getId')->willReturn(31);
        $payment->method('setTransactionId')->willReturnCallback(
            function ($id) use ($payment) {
                $this->appliedTransactionId = $id;

                return $payment;
            }
        );

        return $payment;
    }

    /**
     * The OM status row is our bookkeeping for the capture, so what was saved is recorded rather
     * than any call being accepted.
     */
    private function statusFactory(): OrderManagementStatusInterfaceFactory&MockObject
    {
        $factory = $this->createMock(OrderManagementStatusInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(
            function () {
                $row = new \ArrayObject(['transactionId' => null, 'message' => '']);

                $status = $this->createMock(OrderManagementStatus::class);
                $this->builtRows[spl_object_id($status)] = $row;
                $status->method('setTransactionId')->willReturnCallback(
                    function ($id) use ($row, $status) {
                        $row['transactionId'] = $id;

                        return $status;
                    }
                );
                $status->method('setMessage')->willReturnCallback(
                    function ($message) use ($row, $status) {
                        $row['message'] = (string)$message;

                        return $status;
                    }
                );

                return $status;
            }
        );

        return $factory;
    }

    /**
     * Records on SAVE, not on create: a row built and then dropped is exactly the bookkeeping miss
     * this covers, so counting factory calls would not catch it.
     */
    private function statusRepository(): OrderManagementStatusRepositoryInterface&MockObject
    {
        $repository = $this->createMock(OrderManagementStatusRepositoryInterface::class);
        $repository->method('save')->willReturnCallback(
            function ($status) {
                $this->savedStatuses[] = $this->builtRows[spl_object_id($status)];

                return $status;
            }
        );

        return $repository;
    }

    private function buildCapture(): \Qliro\QliroOne\Model\Management\Payment
    {
        $this->orderManagementApi = $this->createMock(OrderManagementInterface::class);

        $config = $this->createMock(Config::class);
        $config->method('shouldCaptureOnShipment')->willReturn(1);

        $link = $this->createMock(Link::class);
        $link->method('getReference')->willReturn('ZW1YpE');
        $link->method('getQliroOrderId')->willReturn(self::QLIRO_ORDER_ID);

        $linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $linkRepository->method('getByOrderId')->willReturn($link);

        $request = $this->createMock(AdminMarkItemsAsShippedRequestInterface::class);
        $request->method('getShipments')->willReturn([['OrderItems' => []]]);

        $shipmentBuilder = $this->createMock(ShipmentMarkItemsAsShippedRequestBuilder::class);
        $shipmentBuilder->method('setShipment')->willReturnSelf();
        $shipmentBuilder->method('create')->willReturn($request);

        $invoiceBuilder = $this->createMock(InvoiceMarkItemsAsShippedRequestBuilder::class);
        $invoiceBuilder->method('setPayment')->willReturnSelf();
        $invoiceBuilder->method('setAmount')->willReturnSelf();
        $invoiceBuilder->method('create')->willReturn($request);

        $this->order = $this->buildOrder();

        return new \Qliro\QliroOne\Model\Management\Payment(
            $config,
            $this->orderManagementApi,
            $linkRepository,
            $this->createMock(OrderRepositoryInterface::class),
            $this->createMock(LogManager::class),
            $this->createMock(BuilderInterface::class),
            $this->statusFactory(),
            $this->statusRepository(),
            $invoiceBuilder,
            $shipmentBuilder,
            $this->createMock(AddItemsToInvoiceBuilder::class),
            $this->createMock(CaptureRefundAllocator::class),
            $this->createMock(SequentialRefundProcessor::class)
        );
    }

    private function buildOrder(): Order&MockObject
    {
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getStoreId', 'getData', 'setData', 'addStatusHistoryComment'])
            ->getMock();

        // By reference in both, an arrow function would snapshot the array as it is now and the
        // writes below would never be visible to the reads.
        $bag = [];
        $order->method('getId')->willReturn(11);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getData')->willReturnCallback(
            function ($key = null) use (&$bag) {
                return $key === null ? $bag : ($bag[$key] ?? null);
            }
        );
        $order->method('setData')->willReturnCallback(
            function ($key, $value = null) use (&$bag, $order) {
                $bag[$key] = $value;

                return $order;
            }
        );
        $order->method('addStatusHistoryComment')->willReturnCallback(
            function ($comment) {
                $this->orderComments[] = (string)$comment;

                return null;
            }
        );

        return $order;
    }
}
