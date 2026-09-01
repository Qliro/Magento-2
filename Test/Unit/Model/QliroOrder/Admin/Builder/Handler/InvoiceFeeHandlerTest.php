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
use Qliro\QliroOne\Helper\Data as QliroHelper;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\InvoiceFeeHandler;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\LineVatRate;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\InvoiceFeeHandler
 */
class InvoiceFeeHandlerTest extends TestCase
{
    private const MERCHANT_REFERENCE = 'INVOICE_FEE';

    private InvoiceFeeHandler $handler;

    protected function setUp(): void
    {
        $itemFactory = $this->createMock(QliroOrderItemInterfaceFactory::class);
        $itemFactory->method('create')->willReturnCallback(static fn(): Item => new Item());

        $qliroHelper = $this->createMock(QliroHelper::class);
        $qliroHelper->method('formatPrice')
            ->willReturnCallback(static fn($value): string => number_format((float)$value, 2, '.', ''));

        $this->handler = new InvoiceFeeHandler($itemFactory, $qliroHelper, new LineVatRate());
    }

    /**
     * The fee is Qliro's own line, so the rate it was reserved with is the one the capture states.
     */
    public function testSendsTheRateTheFeeWasReservedWith(): void
    {
        $line = $this->buildFeeLine([
            'PricePerItemIncVat' => 29.0,
            'PricePerItemExVat' => 23.2,
            'VatRate' => 25.0,
        ]);

        self::assertSame(29.0, (float)$line->getPricePerItemIncVat());
        self::assertSame(23.2, (float)$line->getPricePerItemExVat());
        self::assertSame(25.0, $line->getVatRate());
    }

    /**
     * An order stored before the fee carried a rate has none, and there the amounts are all there
     * is to state a rate from.
     */
    public function testDerivesTheRateFromTheAmountsWhenTheFeeCarriesNone(): void
    {
        $line = $this->buildFeeLine([
            'PricePerItemIncVat' => 29.0,
            'PricePerItemExVat' => 23.2,
        ]);

        self::assertSame(25.0, $line->getVatRate());
    }

    /**
     * A fee that carries no VAT at all keeps the 0 the amounts state.
     */
    public function testStatesNoRateOnAFeeThatCarriesNoVat(): void
    {
        $line = $this->buildFeeLine([
            'PricePerItemIncVat' => 29.0,
            'PricePerItemExVat' => 29.0,
            'VatRate' => 0.0,
        ]);

        self::assertSame(0.0, $line->getVatRate());
    }

    /**
     * The Qliro API refuses `Input must have no more than two decimal places`, GitHub issue #122,
     * so neither the amounts nor the rate that describes them may carry more. The rate is taken
     * from the amounts as stored, before that rounding: a fee of 4.79 taxed at 25 percent is
     * 5.9875, and reading the rate back off the 5.99 that goes out would state 25.05.
     */
    public function testRoundsTheAmountsAndKeepsTheRateTheyHeldBeforeThat(): void
    {
        $line = $this->buildFeeLine([
            'PricePerItemIncVat' => 5.9875,
            'PricePerItemExVat' => 4.79,
        ]);

        self::assertSame(5.99, (float)$line->getPricePerItemIncVat());
        self::assertSame(4.79, (float)$line->getPricePerItemExVat());
        self::assertSame(25.0, $line->getVatRate());
    }

    /**
     * Only the first capture carries the order level lines, the rest would double them.
     */
    public function testSkipsAnOrderThatIsNotOnItsFirstCapture(): void
    {
        $order = $this->buildOrder([['PricePerItemIncVat' => 29.0, 'PricePerItemExVat' => 23.2]]);
        $order->setData('first_capture_flag', false);

        self::assertSame([], $this->handler->handle([], $order));
    }

    /**
     * An order placed without a fee has nothing on the payment to send.
     */
    public function testSkipsAnOrderWithoutFees(): void
    {
        self::assertSame([], $this->handler->handle([], $this->buildOrder(null)));
    }

    private function buildFeeLine(array $fee): QliroOrderItemInterface
    {
        $orderItems = $this->handler->handle([], $this->buildOrder([$this->buildFee($fee)]));

        self::assertCount(1, $orderItems);
        self::assertSame(QliroOrderItemInterface::TYPE_FEE, $orderItems[0]->getType());
        self::assertSame(self::MERCHANT_REFERENCE, $orderItems[0]->getMerchantReference());

        return $orderItems[0];
    }

    private function buildFee(array $fee): array
    {
        return $fee + [
            'MerchantReference' => self::MERCHANT_REFERENCE,
            'Description' => 'Invoice fee',
            'Type' => QliroOrderItemInterface::TYPE_FEE,
            'Quantity' => 1,
        ];
    }

    private function buildOrder(?array $fees): Order&MockObject
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')
            ->with('qliroone_fees')
            ->willReturn($fees);

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPayment'])
            ->getMock();
        $order->method('getPayment')->willReturn($payment);

        $order->setData(['first_capture_flag' => true]);

        return $order;
    }
}
