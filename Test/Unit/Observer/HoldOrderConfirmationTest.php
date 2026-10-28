<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Observer;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Observer\HoldOrderConfirmation;

/**
 * @see \Qliro\QliroOne\Observer\HoldOrderConfirmation::execute
 */
class HoldOrderConfirmationTest extends TestCase
{
    public function testHoldsTheConfirmationOfAQliroOrder(): void
    {
        $order = $this->order('qliroone');

        (new HoldOrderConfirmation())->execute($this->observer($order));

        self::assertFalse($order->getCanSendNewEmailFlag());
        self::assertTrue($order->getData(HoldOrderConfirmation::HELD));
    }

    public function testDoesNotClaimAConfirmationAlreadyHeldElsewhere(): void
    {
        $order = $this->order('qliroone');
        $order->setCanSendNewEmailFlag(false);

        (new HoldOrderConfirmation())->execute($this->observer($order));

        self::assertNull($order->getData(HoldOrderConfirmation::HELD));
    }

    public function testLeavesOtherPaymentMethodsAlone(): void
    {
        $order = $this->order('checkmo');

        (new HoldOrderConfirmation())->execute($this->observer($order));

        self::assertTrue($order->getCanSendNewEmailFlag());
        self::assertNull($order->getData(HoldOrderConfirmation::HELD));
    }

    public function testIgnoresAnEventWithoutAnOrder(): void
    {
        (new HoldOrderConfirmation())->execute($this->observer(null));

        $this->addToAssertionCount(1);
    }

    private function order(string $method): Order
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn($method);

        // Real flag storage, only the payment is stubbed
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPayment'])
            ->getMock();
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }

    private function observer(?Order $order): Observer
    {
        return new Observer(['event' => new Event(['order' => $order])]);
    }
}
