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

        $this->handler = new ShippingFeeHandler($itemFactory, $qliroHelper);
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
