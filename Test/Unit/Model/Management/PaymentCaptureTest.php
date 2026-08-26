<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Management;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Model\Order;
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
use Qliro\QliroOne\Model\Management\Payment;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\ShipmentMarkItemsAsShippedRequestBuilder;

/**
 * The capture submission contract, per PLIN-381.
 *
 * @see \Qliro\QliroOne\Model\Management\Payment::captureByShipment
 */
class PaymentCaptureTest extends TestCase
{
    private OrderManagementInterface&MockObject $orderManagementApi;
    private Order&MockObject $order;

    /**
     * capture_on_shipment and capture_on_invoice are independent settings and one admin action can
     * fire both paths. The second submission is refused by Qliro as already shipped, and that
     * refusal used to roll the whole action back: money captured, no invoice, order unclosable.
     */
    public function testASecondCaptureInTheSameRequestIsNotSubmitted(): void
    {
        $payment = $this->buildPayment();
        $this->order->setData(AbstractManagement::QLIRO_CAPTURE_SUBMITTED, true);

        $this->orderManagementApi->expects(self::never())->method('markItemsAsShipped');

        $payment->captureByShipment($this->buildShipment($this->order));
    }

    /**
     * The first submission marks the order, which is what makes the check above fire.
     */
    public function testTheFirstCaptureMarksTheOrderAsSubmitted(): void
    {
        $payment = $this->buildPayment();

        $this->orderManagementApi->method('markItemsAsShipped')->willReturn($this->buildResult('Created', 1));

        $payment->captureByShipment($this->buildShipment($this->order));

        self::assertTrue((bool)$this->order->getData(AbstractManagement::QLIRO_CAPTURE_SUBMITTED));
    }

    /**
     * Already shipped means the money moved, so the document must be allowed to complete. Throwing
     * here left the order permanently unclosable: the reservation is spent, so every retry is
     * refused the same way.
     */
    public function testAnAlreadyShippedRefusalDoesNotFailTheDocument(): void
    {
        $payment = $this->buildPayment();
        $this->orderManagementApi->method('markItemsAsShipped')
            ->willThrowException($this->qliroRefusal(AbstractManagement::QLIRO_ERROR_NO_ITEMS_LEFT, 'All items already shipped'));

        $payment->captureByShipment($this->buildShipment($this->order));

        self::assertTrue(true, 'no exception escaped');
    }

    /**
     * An order Qliro has never heard of is not a transient failure, and "Request to Qliro One has
     * failed" told the operator nothing. The message now names the id and says retrying is futile.
     */
    public function testAnUnknownOrderIsReportedWithItsId(): void
    {
        $payment = $this->buildPayment();
        $this->orderManagementApi->method('markItemsAsShipped')
            ->willThrowException($this->qliroRefusal(AbstractManagement::QLIRO_ERROR_ORDER_NOT_FOUND, 'Order not found'));

        try {
            $payment->captureByShipment($this->buildShipment($this->order));
            self::fail('an unknown order must fail the capture');
        } catch (LocalizedException $e) {
            self::assertStringContainsString('5478412', $e->getMessage());
            self::assertStringContainsString('Retrying will not help', $e->getMessage());
        }
    }

    /**
     * Any other refusal keeps its own reason rather than being relabelled.
     */
    public function testAnyOtherRefusalIsPassedThrough(): void
    {
        $payment = $this->buildPayment();
        $refusal = $this->qliroRefusal('INVALID_ITEM', 'Item does not match');
        $this->orderManagementApi->method('markItemsAsShipped')->willThrowException($refusal);

        $this->expectException(OrderManagementApiException::class);

        $payment->captureByShipment($this->buildShipment($this->order));
    }

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

    private function buildShipment(Order $order): Shipment&MockObject
    {
        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getOrder')->willReturn($order);
        $shipment->method('getStoreId')->willReturn(1);
        $shipment->method('getId')->willReturn(7);

        return $shipment;
    }

    private function buildPayment(): Payment
    {
        $this->orderManagementApi = $this->createMock(OrderManagementInterface::class);

        $config = $this->createMock(Config::class);
        $config->method('shouldCaptureOnShipment')->willReturn(1);

        $link = $this->createMock(Link::class);
        $link->method('getReference')->willReturn('ZW1YpE');
        $link->method('getQliroOrderId')->willReturn(5478412);

        $linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $linkRepository->method('getByOrderId')->willReturn($link);

        $request = $this->createMock(AdminMarkItemsAsShippedRequestInterface::class);
        $request->method('getShipments')->willReturn([['OrderItems' => []]]);

        $builder = $this->createMock(ShipmentMarkItemsAsShippedRequestBuilder::class);
        $builder->method('setShipment')->willReturnSelf();
        $builder->method('create')->willReturn($request);

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getStoreId', 'getData', 'setData'])
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

        $payment = new Payment(
            $config,
            $this->orderManagementApi,
            $linkRepository,
            $this->createMock(\Magento\Sales\Api\OrderRepositoryInterface::class),
            $this->createMock(LogManager::class),
            $this->createMock(\Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface::class),
            $this->statusFactory(),
            $this->createMock(OrderManagementStatusRepositoryInterface::class),
            $this->createMock(\Qliro\QliroOne\Model\QliroOrder\Admin\Builder\InvoiceMarkItemsAsShippedRequestBuilder::class),
            $builder,
            $this->createMock(\Qliro\QliroOne\Model\QliroOrder\Admin\Builder\AddItemsToInvoiceBuilder::class),
            $this->createMock(\Qliro\QliroOne\Model\QliroOrder\Admin\CaptureRefundAllocator::class),
            $this->createMock(\Qliro\QliroOne\Model\QliroOrder\Admin\SequentialRefundProcessor::class)
        );

        $this->order = $order;

        return $payment;
    }

    /**
     * The OM status record is written inside its own try/catch, so a bare stub is enough.
     */
    private function statusFactory(): OrderManagementStatusInterfaceFactory&MockObject
    {
        $status = $this->createMock(\Qliro\QliroOne\Model\OrderManagementStatus::class);
        $factory = $this->createMock(OrderManagementStatusInterfaceFactory::class);
        $factory->method('create')->willReturn($status);

        return $factory;
    }
}
