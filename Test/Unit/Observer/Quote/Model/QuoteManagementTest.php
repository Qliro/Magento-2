<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Observer\Quote\Model;

use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use Magento\Quote\Model\Quote\Payment;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\QliroOrder\Builder\OrderItemsBuilder;
use Qliro\QliroOne\Model\QliroOrder\LineQuantity;
use Qliro\QliroOne\Model\Quote\ItemsLimitValidator;
use Qliro\QliroOne\Model\Quote\WholeQuantityValidator;
use Qliro\QliroOne\Observer\Quote\Model\QuoteManagement;

/**
 * The last point a cart Qliro cannot take can still be refused: after this the quote is an order,
 * and the buyer has paid for it.
 *
 * @see \Qliro\QliroOne\Observer\Quote\Model\QuoteManagement
 */
class QuoteManagementTest extends TestCase
{
    /**
     * A cart holding half a metre never becomes an order paid with this method.
     */
    public function testRefusesACartHoldingAPartOfAnItem(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/CABLE-5MM/');

        $this->observer()->execute($this->event($this->quote(['CABLE-5MM' => 0.5], 'qliroone')));
    }

    public function testLetsACartOfWholeQuantitiesThrough(): void
    {
        $this->expectNotToPerformAssertions();

        $this->observer()->execute($this->event($this->quote(['SKU-1' => 2.0], 'qliroone')));
    }

    /**
     * A cart paid for with another method is none of this module's business, whatever it holds.
     */
    public function testLeavesACartPaidForWithAnotherMethodAlone(): void
    {
        $this->expectNotToPerformAssertions();

        $this->observer()->execute($this->event($this->quote(['CABLE-5MM' => 0.5], 'checkmo')));
    }

    private function observer(): QuoteManagement
    {
        $orderItemsBuilder = $this->createMock(OrderItemsBuilder::class);
        $orderItemsBuilder->method('setQuote')->willReturnSelf();
        $orderItemsBuilder->method('create')->willReturn([]);

        return new QuoteManagement(
            new ItemsLimitValidator($orderItemsBuilder),
            new WholeQuantityValidator(new LineQuantity())
        );
    }

    private function event(Quote $quote): Observer
    {
        $event = $this->getMockBuilder(Event::class)
            ->disableOriginalConstructor()
            ->addMethods(['getQuote'])
            ->getMock();
        $event->method('getQuote')->willReturn($quote);

        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);

        return $observer;
    }

    /**
     * @param array<string, float> $quantities Quantity per sku
     */
    private function quote(array $quantities, string $paymentMethod): Quote
    {
        $items = [];

        foreach ($quantities as $sku => $qty) {
            $item = $this->createMock(QuoteItem::class);
            $item->method('getSku')->willReturn($sku);
            $item->method('getQty')->willReturn($qty);
            $items[] = $item;
        }

        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn($paymentMethod);

        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(11);
        $quote->method('getAllItems')->willReturn($items);
        $quote->method('getPayment')->willReturn($payment);

        return $quote;
    }
}
