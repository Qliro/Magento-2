<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Admin\Builder\Handler;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\RoundingAdjustmentHandler;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\RoundingAdjustment;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\RoundingAdjustmentHandler
 */
class RoundingAdjustmentHandlerTest extends TestCase
{
    private const STAMP = ['incVat' => -0.25, 'exVat' => -0.2, 'vatRate' => 25.0, 'description' => 'Rounding'];

    private RoundingAdjustmentHandler $handler;

    protected function setUp(): void
    {
        $itemFactory = $this->createMock(QliroOrderItemInterfaceFactory::class);
        $itemFactory->method('create')->willReturnCallback(static fn(): Item => new Item());

        $this->handler = new RoundingAdjustmentHandler(new RoundingAdjustment($itemFactory));
    }

    /**
     * The capture has to state the line exactly as the reservation holds it, so it is replayed
     * off the order rather than derived from the items being invoiced.
     */
    public function testReplaysTheReservedLineOnTheFirstCapture(): void
    {
        $orderItems = $this->handler->handle(['a product line'], $this->buildOrder(true, self::STAMP));

        self::assertCount(2, $orderItems);
        self::assertSame('ROUNDING', $orderItems[1]->getMerchantReference());
        self::assertSame(QliroOrderItemInterface::TYPE_DISCOUNT, $orderItems[1]->getType());
        self::assertSame(-0.25, $orderItems[1]->getPricePerItemIncVat());
        self::assertSame(-0.2, $orderItems[1]->getPricePerItemExVat());
        self::assertSame(25.0, $orderItems[1]->getVatRate());
        self::assertSame('Rounding', $orderItems[1]->getDescription());
    }

    /**
     * The amount belongs to the cart as a whole, like the discount, so a later invoice does not
     * take it a second time.
     */
    public function testSendsItOnceAcrossTheInvoices(): void
    {
        self::assertSame(['a product line'], $this->handler->handle(['a product line'], $this->buildOrder(false, self::STAMP)));
    }

    /**
     * An order placed before the line existed was reserved without one, and Qliro refuses a
     * capture that does not match the reservation.
     */
    public function testSendsNothingForAnOrderReservedWithoutOne(): void
    {
        self::assertSame(['a product line'], $this->handler->handle(['a product line'], $this->buildOrder(true, null)));
    }

    /**
     * Anything that is not an order of ours is left alone.
     */
    public function testIgnoresWhatItCannotRead(): void
    {
        self::assertSame(['untouched'], $this->handler->handle(['untouched'], null));

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPayment'])
            ->getMock();
        $order->method('getPayment')->willReturn(null);
        $order->setData('first_capture_flag', true);

        self::assertSame(['untouched'], $this->handler->handle(['untouched'], $order));
    }

    /**
     * @param bool $isFirstCapture
     * @param array<string, float|string>|null $stamp
     * @return Order&MockObject
     */
    private function buildOrder(bool $isFirstCapture, ?array $stamp): Order&MockObject
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')
            ->with(Config::QLIROONE_ADDITIONAL_INFO_ROUNDING_ADJUSTMENT)
            ->willReturn($stamp);

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPayment'])
            ->getMock();
        $order->method('getPayment')->willReturn($payment);
        $order->setData('first_capture_flag', $isFirstCapture);

        return $order;
    }
}
