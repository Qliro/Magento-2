<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder\Handler;

use Magento\Framework\DataObject;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\Builder\Handler\RoundingAdjustmentHandler;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\LinesTotal;
use Qliro\QliroOne\Model\QliroOrder\RoundingAdjustment;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Builder\Handler\RoundingAdjustmentHandler
 */
class RoundingAdjustmentHandlerTest extends TestCase
{
    private RoundingAdjustmentHandler $handler;

    private LogManager&MockObject $logManager;

    protected function setUp(): void
    {
        $itemFactory = $this->createMock(QliroOrderItemInterfaceFactory::class);
        $itemFactory->method('create')->willReturnCallback(static fn(): Item => new Item());
        $this->logManager = $this->createMock(LogManager::class);

        $this->handler = new RoundingAdjustmentHandler(
            new RoundingAdjustment($itemFactory),
            new LinesTotal(),
            $this->logManager
        );
    }

    /**
     * Skyltexperten's cart of 50 Kanalplast: the store charges 2871.25 and the line says
     * 50 x 57.43, so the difference goes out as a line of its own and the two totals agree.
     */
    public function testClosesTheDifferenceBetweenTheCartAndTheLines(): void
    {
        $orderItems = $this->handler->handle([$this->line(50, 57.43)], $this->buildQuote(2871.25));

        self::assertCount(2, $orderItems);
        self::assertSame('ROUNDING', $orderItems[1]->getMerchantReference());
        self::assertSame(-0.25, $orderItems[1]->getPricePerItemIncVat());
    }

    /**
     * A cart whose lines already add up gets no line it does not need.
     */
    public function testLeavesACartThatAddsUpAlone(): void
    {
        $lines = [$this->line(2, 100.0)];

        self::assertSame($lines, $this->handler->handle($lines, $this->buildQuote(200.0)));
    }

    /**
     * The delivery and the invoice fee are Qliro's own lines, so they are not what the module's
     * lines are measured against.
     */
    public function testMeasuresAgainstTheCartWithoutTheDeliveryAndTheFee(): void
    {
        $quote = $this->buildQuote(2959.25, ['shipping_incl_tax' => '59.0000', 'qliroone_fee' => '29.0000']);

        $orderItems = $this->handler->handle([$this->line(50, 57.43)], $quote);

        self::assertCount(2, $orderItems);
        self::assertSame(-0.25, $orderItems[1]->getPricePerItemIncVat());
    }

    /**
     * A disagreement this handler will not absorb is the cart that reaches support, because the
     * checkout locks over it and nothing else says what it was.
     */
    public function testSaysInTheLogWhatItRefusedToAbsorb(): void
    {
        $this->logManager->expects(self::once())
            ->method('debug')
            ->with(
                self::stringContains('more than rounding can explain'),
                self::callback(static fn(array $context): bool =>
                    $context['extra']['difference_inc_vat'] === -10.0
                    && $context['extra']['largest_rounding_can_explain'] === 0.015)
            );

        $orderItems = [$this->line(1, 100.0)];

        self::assertSame($orderItems, $this->handler->handle($orderItems, $this->buildQuote(90.0)));
    }

    /**
     * A cart that adds up is not worth a line in the log either.
     */
    public function testSaysNothingAboutACartThatAddsUp(): void
    {
        $this->logManager->expects(self::never())->method('debug');

        $this->handler->handle([$this->line(2, 100.0)], $this->buildQuote(200.0));
    }

    /**
     * Anything that is not a quote, and a build that produced no lines at all, are left alone.
     */
    public function testIgnoresWhatItCannotMeasure(): void
    {
        self::assertSame(['untouched'], $this->handler->handle(['untouched'], null));
        self::assertSame([], $this->handler->handle([], $this->buildQuote(2871.25)));
    }

    /**
     * @param float $grandTotal
     * @param array<string, string> $totals
     * @return Quote&MockObject
     */
    private function buildQuote(float $grandTotal, array $totals = []): Quote&MockObject
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isVirtual', 'getShippingAddress'])
            ->getMock();
        $quote->method('isVirtual')->willReturn(false);
        $quote->setData('grand_total', $grandTotal);
        $quote->setData('entity_id', 1442);
        $quote->method('getShippingAddress')->willReturn(new DataObject($totals));

        return $quote;
    }

    /**
     * @param float $quantity
     * @param float $priceIncVat
     * @return QliroOrderItemInterface
     */
    private function line(float $quantity, float $priceIncVat): QliroOrderItemInterface
    {
        return (new Item())
            ->setType(QliroOrderItemInterface::TYPE_PRODUCT)
            ->setQuantity($quantity)
            ->setPricePerItemIncVat($priceIncVat)
            ->setVatRate(25.0);
    }
}
