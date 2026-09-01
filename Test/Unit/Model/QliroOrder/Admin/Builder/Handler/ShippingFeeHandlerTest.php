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
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\ShippingFeeHandler;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\LineVatRate;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\ShippingFeeHandler
 */
class ShippingFeeHandlerTest extends TestCase
{
    private const MERCHANT_REFERENCE = 'SHIPPING_FLATRATE';

    private ShippingFeeHandler $handler;

    protected function setUp(): void
    {
        $itemFactory = $this->createMock(QliroOrderItemInterfaceFactory::class);
        $itemFactory->method('create')->willReturnCallback(static fn(): Item => new Item());

        $qliroHelper = $this->createMock(QliroHelper::class);
        $qliroHelper->method('formatPrice')
            ->willReturnCallback(static fn($value): string => number_format((float)$value, 2, '.', ''));

        $this->handler = new ShippingFeeHandler($itemFactory, $qliroHelper, new LineVatRate());
    }

    /**
     * Undiscounted shipping, where the two sources agree and nothing changes.
     */
    public function testSendsTheShippingPriceIncludingItsVat(): void
    {
        $line = $this->buildShippingLine(
            shippingAmount: 50.0,
            shippingInclTax: 62.5,
            shippingTaxAmount: 12.5
        );

        self::assertSame(62.5, (float)$line->getPricePerItemIncVat());
        self::assertSame(50.0, (float)$line->getPricePerItemExVat());
        self::assertSame(25.0, $line->getVatRate());
    }

    /**
     * A rule that discounts the shipping drops `shipping_tax_amount`, which is the tax after the
     * discount, while the checkout reserved the shipping at its undiscounted price. Building the
     * line from that figure made the capture short by the VAT the discount took off the shipping,
     * and the discount line adds that same VAT back, so it was subtracted twice.
     */
    public function testSendsTheUndiscountedVatWhenTheRuleDiscountsShipping(): void
    {
        $line = $this->buildShippingLine(
            shippingAmount: 50.0,
            shippingInclTax: 62.5,
            shippingTaxAmount: 11.25
        );

        self::assertSame(62.5, (float)$line->getPricePerItemIncVat());
        self::assertSame(50.0, (float)$line->getPricePerItemExVat());
        self::assertSame(25.0, $line->getVatRate());
    }

    /**
     * An order from before Magento stored the inc VAT shipping falls back to the sum, which is
     * what this handler always used.
     */
    public function testFallsBackToTheTaxAmountWhenTheOrderHasNoInclusivePrice(): void
    {
        $line = $this->buildShippingLine(
            shippingAmount: 50.0,
            shippingInclTax: null,
            shippingTaxAmount: 12.5
        );

        self::assertSame(62.5, (float)$line->getPricePerItemIncVat());
    }

    /**
     * The rate describes the amounts the line is sent with, so it is taken from them rather than
     * from a shipping tax rate looked up elsewhere, and it carries no more than two decimals: the
     * Qliro API refuses `Input must have no more than two decimal places`, GitHub issue #122.
     */
    public function testStatesARateWithTwoDecimalsThatTheAmountsProduce(): void
    {
        $line = $this->buildShippingLine(
            shippingAmount: 29.0,
            shippingInclTax: 36.0,
            shippingTaxAmount: 7.0
        );

        self::assertSame(24.14, $line->getVatRate());
    }

    /**
     * The rate comes from the amounts before they are rounded for sending. Deriving it from the
     * rounded figures would state 25.05 on a shipping price of 4.79 taxed at 25 percent, a rate no
     * jurisdiction charges, because 5.9875 goes out as 5.99.
     */
    public function testStatesTheRateTheAmountsHoldBeforeTheyAreRounded(): void
    {
        $line = $this->buildShippingLine(
            shippingAmount: 4.79,
            shippingInclTax: 5.9875,
            shippingTaxAmount: 1.1975
        );

        self::assertSame(5.99, (float)$line->getPricePerItemIncVat());
        self::assertSame(4.79, (float)$line->getPricePerItemExVat());
        self::assertSame(25.0, $line->getVatRate());
    }

    /**
     * Free shipping carries no VAT, and a rate on a line of zero would state one it does not hold.
     */
    public function testStatesNoRateOnShippingThatCostsNothing(): void
    {
        $line = $this->buildShippingLine(
            shippingAmount: 0.0,
            shippingInclTax: 0.0,
            shippingTaxAmount: 0.0
        );

        self::assertSame(0.0, $line->getVatRate());
    }

    /**
     * A store that charges no tax on shipping sends the same amount on both fields, and 0 is the
     * rate that describes it.
     */
    public function testStatesNoRateOnUntaxedShipping(): void
    {
        $line = $this->buildShippingLine(
            shippingAmount: 50.0,
            shippingInclTax: 50.0,
            shippingTaxAmount: 0.0
        );

        self::assertSame(0.0, $line->getVatRate());
    }

    /**
     * Only the first capture carries the order level lines, the rest would double them.
     */
    public function testSkipsAnOrderThatIsNotOnItsFirstCapture(): void
    {
        $order = $this->buildOrder(50.0, 62.5, 12.5);
        $order->setData('first_capture_flag', false);

        self::assertSame([], $this->handler->handle([], $order));
    }

    /**
     * Without the merchant reference the checkout used there is no line Qliro would recognise.
     */
    public function testSkipsAnOrderWithoutTheShippingMerchantReference(): void
    {
        $order = $this->buildOrder(50.0, 62.5, 12.5, merchantReference: null);

        self::assertSame([], $this->handler->handle([], $order));
    }

    private function buildShippingLine(
        float $shippingAmount,
        ?float $shippingInclTax,
        float $shippingTaxAmount
    ): QliroOrderItemInterface {
        $orderItems = $this->handler->handle(
            [],
            $this->buildOrder($shippingAmount, $shippingInclTax, $shippingTaxAmount)
        );

        self::assertCount(1, $orderItems);
        self::assertSame(QliroOrderItemInterface::TYPE_SHIPPING, $orderItems[0]->getType());
        self::assertSame(self::MERCHANT_REFERENCE, $orderItems[0]->getMerchantReference());

        return $orderItems[0];
    }

    private function buildOrder(
        float $shippingAmount,
        ?float $shippingInclTax,
        float $shippingTaxAmount,
        ?string $merchantReference = self::MERCHANT_REFERENCE
    ): Order&MockObject {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturn(
            $merchantReference === null
                ? []
                : [ShippingFeeHandler::MERCHANT_REFERENCE_CODE_FIELD => $merchantReference]
        );

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPayment'])
            ->getMock();
        $order->method('getPayment')->willReturn($payment);

        $order->setData([
            'first_capture_flag' => true,
            'shipping_amount' => $shippingAmount,
            'shipping_incl_tax' => $shippingInclTax,
            'shipping_tax_amount' => $shippingTaxAmount,
        ]);

        return $order;
    }
}
