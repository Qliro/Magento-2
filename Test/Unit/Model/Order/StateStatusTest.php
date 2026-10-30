<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Order;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Order\StateStatus;

/**
 * @see \Qliro\QliroOne\Model\Order\StateStatus
 */
class StateStatusTest extends TestCase
{
    private const REGISTERED = [
        Order::STATE_NEW => ['pending', 'qliro_pending'],
        Order::STATE_PENDING_PAYMENT => ['pending_payment'],
        Order::STATE_PAYMENT_REVIEW => ['fraud', 'payment_review'],
        Order::STATE_PROCESSING => ['processing'],
        'custom' => ['custom_first', 'custom_second'],
    ];

    private const DEFAULTS = [
        Order::STATE_NEW => 'pending',
        Order::STATE_PENDING_PAYMENT => 'pending_payment',
        Order::STATE_PAYMENT_REVIEW => 'payment_review',
        Order::STATE_PROCESSING => 'processing',
        'custom' => null,
    ];

    private LogManager&MockObject $logManager;

    protected function setUp(): void
    {
        $this->logManager = $this->createMock(LogManager::class);
    }

    public function testKeepsAStatusRegisteredForTheState(): void
    {
        $order = $this->order(Order::STATE_PAYMENT_REVIEW, 'payment_review');
        $this->logManager->expects(self::never())->method('warning');

        (new StateStatus($this->logManager))->set($order, Order::STATE_NEW, 'qliro_pending');

        self::assertSame([Order::STATE_NEW, 'qliro_pending'], [$order->getState(), $order->getStatus()]);
    }

    public function testWithoutAStatusTheStateDefaultIsUsedQuietly(): void
    {
        $order = $this->order(Order::STATE_PROCESSING, 'processing');
        $this->logManager->expects(self::never())->method('warning');

        (new StateStatus($this->logManager))->set($order, Order::STATE_PENDING_PAYMENT);

        self::assertSame(
            [Order::STATE_PENDING_PAYMENT, 'pending_payment'],
            [$order->getState(), $order->getStatus()]
        );
    }

    public function testAProcessingOrderAskingForANewStatusGetsTheProcessingDefault(): void
    {
        $order = $this->order(Order::STATE_PROCESSING, 'processing');
        $this->logManager->expects(self::once())->method('warning')
            ->with('Status qliro_pending is not assigned to state processing, the order gets processing');

        (new StateStatus($this->logManager))->set($order, Order::STATE_PROCESSING, 'qliro_pending');

        self::assertSame([Order::STATE_PROCESSING, 'processing'], [$order->getState(), $order->getStatus()]);
    }

    public function testAStateWithoutADefaultGetsItsFirstRegisteredStatus(): void
    {
        $order = $this->order(Order::STATE_NEW, 'pending');
        $this->logManager->expects(self::once())->method('warning');

        (new StateStatus($this->logManager))->set($order, 'custom', 'pending');

        self::assertSame('custom_first', $order->getStatus());
    }

    public function testAFallbackWithoutAskedStatusIsLoggedToo(): void
    {
        $order = $this->order(Order::STATE_NEW, 'pending');
        $this->logManager->expects(self::once())->method('warning')
            ->with('State custom has no default status, the order gets custom_first');

        (new StateStatus($this->logManager))->set($order, 'custom');

        self::assertSame('custom_first', $order->getStatus());
    }

    /**
     * @dataProvider checkoutStates
     */
    public function testACheckoutStatusMovesAnOrderStillInCheckout(string $state, string $status): void
    {
        $order = $this->order($state, $status);

        self::assertTrue((new StateStatus($this->logManager))->setFromCheckout($order, Order::STATE_NEW, 'pending'));
        self::assertSame([Order::STATE_NEW, 'pending'], [$order->getState(), $order->getStatus()]);
    }

    public static function checkoutStates(): array
    {
        return [
            'new' => [Order::STATE_NEW, 'pending'],
            'pending payment' => [Order::STATE_PENDING_PAYMENT, 'pending_payment'],
            'payment review' => [Order::STATE_PAYMENT_REVIEW, 'payment_review'],
        ];
    }

    /**
     * A checkout status delivered again after the merchant invoiced the order
     *
     * @dataProvider pastCheckoutStates
     */
    public function testACheckoutStatusLeavesAnOrderPastCheckoutAlone(string $state, string $status): void
    {
        $order = $this->order($state, $status);
        $this->logManager->expects(self::once())->method('warning')
            ->with(sprintf('Order is already %s / %s, it is not moved back to new', $state, $status));

        self::assertFalse((new StateStatus($this->logManager))->setFromCheckout($order, Order::STATE_NEW, 'pending'));
        self::assertSame([$state, $status], [$order->getState(), $order->getStatus()]);
    }

    public static function pastCheckoutStates(): array
    {
        return [
            'processing' => [Order::STATE_PROCESSING, 'processing'],
            'merchant status' => [Order::STATE_PROCESSING, 'packed'],
            'complete' => [Order::STATE_COMPLETE, 'complete'],
            'canceled' => [Order::STATE_CANCELED, 'canceled'],
        ];
    }

    public function testAHeldOrderInCheckoutKeepsItsHold(): void
    {
        $order = $this->order(Order::STATE_HOLDED, 'holded');
        $order->setHoldBeforeState(Order::STATE_PENDING_PAYMENT);
        $order->setHoldBeforeStatus('pending_payment');

        self::assertTrue((new StateStatus($this->logManager))->setFromCheckout($order, Order::STATE_NEW, 'fraud'));
        self::assertSame(
            [Order::STATE_HOLDED, 'holded', Order::STATE_NEW, 'pending'],
            [$order->getState(), $order->getStatus(), $order->getHoldBeforeState(), $order->getHoldBeforeStatus()]
        );
    }

    /**
     * Held after a failed capture
     */
    public function testAHeldOrderPastCheckoutIsLeftAlone(): void
    {
        $order = $this->order(Order::STATE_HOLDED, 'holded');
        $order->setHoldBeforeState(Order::STATE_PROCESSING);
        $order->setHoldBeforeStatus('processing');
        $this->logManager->expects(self::once())->method('warning');

        self::assertFalse((new StateStatus($this->logManager))->setFromCheckout($order, Order::STATE_NEW, 'pending'));
        self::assertSame(
            [Order::STATE_HOLDED, Order::STATE_PROCESSING, 'processing'],
            [$order->getState(), $order->getHoldBeforeState(), $order->getHoldBeforeStatus()]
        );
    }

    public function testAHeldOrderWithoutAStateToReturnToIsLeftAlone(): void
    {
        $order = $this->order(Order::STATE_HOLDED, 'holded');
        $this->logManager->expects(self::once())->method('warning');

        self::assertFalse((new StateStatus($this->logManager))->setFromCheckout($order, Order::STATE_NEW));
        self::assertSame([Order::STATE_HOLDED, null], [$order->getState(), $order->getHoldBeforeState()]);
    }

    private function order(string $state, string $status): Order&MockObject
    {
        $config = $this->createMock(OrderConfig::class);
        $config->method('getStateStatuses')
            ->willReturnCallback(static fn($state) => self::REGISTERED[$state] ?? []);
        $config->method('getStateDefaultStatus')
            ->willReturnCallback(static fn($state) => self::DEFAULTS[$state] ?? null);

        $order = $this->createPartialMock(Order::class, ['getConfig', 'getId']);
        $order->method('getConfig')->willReturn($config);
        $order->method('getId')->willReturn('42');
        $order->setState($state);
        $order->setStatus($status);

        return $order;
    }
}
