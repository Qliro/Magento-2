<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\OrderManagementStatus\Update\Handler;

use Magento\Sales\Api\InvoiceRepositoryInterface;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Shipment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\Notification\QliroOrderManagementStatus;
use Qliro\QliroOne\Model\Order\StateStatus;
use Qliro\QliroOne\Model\OrderManagementStatus;
use Qliro\QliroOne\Model\OrderManagementStatus\Update\CaptureHold;
use Qliro\QliroOne\Model\OrderManagementStatus\Update\CaptureTransactionUpdater;
use Qliro\QliroOne\Model\OrderManagementStatus\Update\Handler\Payment as PaymentHandler;
use Qliro\QliroOne\Model\OrderManagementStatus\Update\Handler\Shipment as ShipmentHandler;

/**
 * A capture Qliro returns OnHold sets the order to pending payment, a held order keeps its hold
 *
 * @see \Qliro\QliroOne\Model\OrderManagementStatus\Update\Handler\Payment::handleOnHold
 * @see \Qliro\QliroOne\Model\OrderManagementStatus\Update\Handler\Shipment::handleOnHold
 */
class CaptureOnHoldTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;

    /** @var string[] */
    private array $comments = [];

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->comments = [];
    }

    /**
     * @dataProvider handlers
     */
    public function testAHeldOrderKeepsItsHold(string $handler): void
    {
        $order = $this->order(Order::STATE_HOLDED, 'holded');
        $order->setHoldBeforeState(Order::STATE_PROCESSING);
        $order->setHoldBeforeStatus('processing');
        $this->orderRepository->expects(self::once())->method('save');

        $this->onHold($handler, $order);

        self::assertSame(
            [Order::STATE_HOLDED, 'holded', Order::STATE_PENDING_PAYMENT, 'pending_payment'],
            [$order->getState(), $order->getStatus(), $order->getHoldBeforeState(), $order->getHoldBeforeStatus()]
        );
        self::assertSame(
            ['Qliro One returned the capture with status: OnHold, the order stays on hold'],
            $this->comments
        );
    }

    /**
     * @dataProvider handlers
     */
    public function testAnOrderNotHeldIsPendingPayment(string $handler): void
    {
        $order = $this->order(Order::STATE_PROCESSING, 'processing');
        $this->orderRepository->expects(self::once())->method('save');

        $this->onHold($handler, $order);

        self::assertSame([Order::STATE_PENDING_PAYMENT, 'pending_payment'], [$order->getState(), $order->getStatus()]);
        self::assertSame(
            ['Order set to pending payment because Qliro One returned the capture with status: OnHold'],
            $this->comments
        );
    }

    /**
     * @dataProvider handlers
     */
    public function testAHeldOrderReturningToPendingPaymentIsLeftAlone(string $handler): void
    {
        $order = $this->order(Order::STATE_HOLDED, 'holded');
        $order->setHoldBeforeState(Order::STATE_PENDING_PAYMENT);
        $order->setHoldBeforeStatus('pending_payment');
        $this->orderRepository->expects(self::never())->method('save');

        $this->onHold($handler, $order);

        self::assertSame([], $this->comments);
    }

    public static function handlers(): array
    {
        return ['payment' => ['payment'], 'shipment' => ['shipment']];
    }

    private function onHold(string $handler, Order $order): void
    {
        $stateStatus = new StateStatus($this->createMock(Manager::class));
        $captureHold = new CaptureHold($stateStatus);
        $omStatus = $this->createMock(OrderManagementStatus::class);
        $omStatus->method('getRecordId')->willReturn(7);
        $status = $this->createMock(QliroOrderManagementStatus::class);

        if ($handler === 'payment') {
            $payment = $this->createMock(Payment::class);
            $payment->method('getOrder')->willReturn($order);
            $paymentRepository = $this->createMock(OrderPaymentRepositoryInterface::class);
            $paymentRepository->method('get')->willReturn($payment);

            (new PaymentHandler(
                $paymentRepository,
                $this->orderRepository,
                $this->createMock(Manager::class),
                $this->createMock(CaptureTransactionUpdater::class),
                $captureHold,
                $stateStatus
            ))->handleOnHold($status, $omStatus);

            return;
        }

        $shipment = $this->createMock(Shipment::class);
        $shipment->method('getOrder')->willReturn($order);
        $shipmentRepository = $this->createMock(ShipmentRepositoryInterface::class);
        $shipmentRepository->method('get')->willReturn($shipment);

        (new ShipmentHandler(
            $shipmentRepository,
            $this->orderRepository,
            $this->createMock(InvoiceRepositoryInterface::class),
            $this->createMock(Manager::class),
            $this->createMock(CaptureTransactionUpdater::class),
            $captureHold,
            stateStatus: $stateStatus
        ))->handleOnHold($status, $omStatus);
    }

    private function order(string $state, string $status): Order&MockObject
    {
        $config = $this->createMock(OrderConfig::class);
        $config->method('getStateStatuses')->willReturnCallback(fn (string $state): array => [$state]);
        $config->method('getStateDefaultStatus')->willReturnArgument(0);

        $order = $this->createPartialMock(Order::class, ['getConfig', 'getId', 'addStatusHistoryComment']);
        $order->method('getConfig')->willReturn($config);
        $order->method('getId')->willReturn('42');
        $order->method('addStatusHistoryComment')->willReturnCallback(function ($comment) use ($order) {
            $this->comments[] = (string)$comment;

            return $order;
        });
        $order->setState($state);
        $order->setStatus($status);

        return $order;
    }
}
