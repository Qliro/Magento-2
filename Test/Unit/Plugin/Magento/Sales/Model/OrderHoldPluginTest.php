<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Plugin\Magento\Sales\Model;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\OrderManagementStatus\Update\CaptureHold;
use Qliro\QliroOne\Plugin\Magento\Sales\Model\OrderHoldPlugin;

/**
 * A hold or release by the merchant ends the tracking, one by CaptureHold keeps it
 */
class OrderHoldPluginTest extends TestCase
{
    public function testAReleaseByHandEndsTheTracking(): void
    {
        $order = $this->order('qliroone');

        (new OrderHoldPlugin(new CaptureHold()))->afterUnhold($order, $order);

        self::assertNull($order->getPayment()->getAdditionalInformation(CaptureHold::KEY_FAILED));
        self::assertNull($order->getPayment()->getAdditionalInformation(CaptureHold::KEY_HELD));
    }

    public function testAHoldByTheModuleKeepsTheTracking(): void
    {
        $order = $this->order('qliroone');
        $order->setData(CaptureHold::OWN_ACTION, true);

        (new OrderHoldPlugin(new CaptureHold()))->afterHold($order, $order);

        self::assertSame(['555'], $order->getPayment()->getAdditionalInformation(CaptureHold::KEY_FAILED));
    }

    public function testAnotherPaymentMethodIsLeftAlone(): void
    {
        $order = $this->order('checkmo');

        (new OrderHoldPlugin(new CaptureHold()))->afterHold($order, $order);

        self::assertSame(['555'], $order->getPayment()->getAdditionalInformation(CaptureHold::KEY_FAILED));
    }

    private function order(string $method): Order
    {
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $payment->setData('method', $method);
        $payment->setAdditionalInformation(CaptureHold::KEY_FAILED, ['555']);
        $payment->setAdditionalInformation(CaptureHold::KEY_HELD, true);

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPayment'])
            ->getMock();
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }
}
