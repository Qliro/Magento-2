<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Order;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Order\PlacedOrderFinder;

/**
 * @see \Qliro\QliroOne\Model\Order\PlacedOrderFinder
 */
class PlacedOrderFinderTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilder;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->searchCriteriaBuilder->method('addFilter')->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));
    }

    public function testFindsTheOrderPlacedForTheQliroOrder(): void
    {
        $order = $this->order('900100');
        $this->searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $this->searchCriteriaBuilder->expects(self::once())->method('addFilter')
            ->with('quote_id', 17)
            ->willReturnSelf();
        $this->searchCriteriaBuilder->method('create')->willReturn($this->createMock(SearchCriteria::class));
        $this->ordersOfTheQuote([$order]);

        self::assertSame($order, $this->finder()->find(17, '900100'));
    }

    /**
     * The buyer may have left Qliro and paid the same quote with another method
     */
    public function testSkipsAnOrderOfTheQuotePaidAnotherWay(): void
    {
        $qliroOrder = $this->order('900100');
        $this->ordersOfTheQuote([$this->order(null), $this->order('800200'), $qliroOrder]);

        self::assertSame($qliroOrder, $this->finder()->find(17, '900100'));
    }

    public function testNullWhenTheQuoteHasNoOrder(): void
    {
        $this->ordersOfTheQuote([]);

        self::assertNull($this->finder()->find(17, '900100'));
    }

    public function testDoesNotSearchWithoutAQuoteOrQliroOrder(): void
    {
        $this->orderRepository->expects(self::never())->method('getList');

        self::assertNull($this->finder()->find(0, '900100'));
        self::assertNull($this->finder()->find(17, ''));
    }

    private function ordersOfTheQuote(array $orders): void
    {
        $result = $this->createMock(OrderSearchResultInterface::class);
        $result->method('getItems')->willReturn($orders);
        $this->orderRepository->method('getList')->willReturn($result);
    }

    private function order(?string $qliroOrderId): Order&MockObject
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')
            ->with(Config::QLIROONE_ADDITIONAL_INFO_QLIRO_ORDER_ID)
            ->willReturn($qliroOrderId);

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }

    private function finder(): PlacedOrderFinder
    {
        return new PlacedOrderFinder($this->orderRepository, $this->searchCriteriaBuilder);
    }
}
