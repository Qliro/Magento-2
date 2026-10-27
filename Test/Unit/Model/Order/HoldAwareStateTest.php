<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Order;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Order\HoldAwareState;

/**
 * @see \Qliro\QliroOne\Model\Order\HoldAwareState
 */
class HoldAwareStateTest extends TestCase
{
    public function testAnOrderNotHeldGetsTheState(): void
    {
        $order = $this->order(Order::STATE_PENDING_PAYMENT);

        HoldAwareState::apply($order, Order::STATE_NEW, 'qliro_new');

        self::assertSame([Order::STATE_NEW, 'qliro_new'], [$order->getState(), $order->getStatus()]);
    }

    public function testAHeldOrderKeepsItsHoldAndGetsTheStateToReturnTo(): void
    {
        $order = $this->order(Order::STATE_HOLDED);

        HoldAwareState::apply($order, Order::STATE_PENDING_PAYMENT);

        self::assertSame(Order::STATE_HOLDED, $order->getState());
        self::assertSame([Order::STATE_PENDING_PAYMENT, 'pending_payment'], [$order->getHoldBeforeState(), $order->getHoldBeforeStatus()]);
    }

    private function order(string $state): Order
    {
        $config = $this->createMock(OrderConfig::class);
        $config->method('getStateDefaultStatus')->willReturnMap([[Order::STATE_PENDING_PAYMENT, 'pending_payment']]);

        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['getConfig'])->getMock();
        $order->method('getConfig')->willReturn($config);
        $order->setState($state);

        return $order;
    }
}
