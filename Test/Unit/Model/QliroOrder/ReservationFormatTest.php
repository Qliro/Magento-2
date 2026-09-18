<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\AdminOrderInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\Admin\OrderItemAction;
use Qliro\QliroOne\Model\QliroOrder\LineReference;
use Qliro\QliroOne\Model\QliroOrder\ReservationFormat;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\ReservationFormat
 */
class ReservationFormatTest extends TestCase
{
    private const QLIRO_ORDER_ID = 278812092;

    /**
     * @var OrderManagementInterface&MockObject
     */
    private $orderManagementApi;

    private ReservationFormat $reservationFormat;

    protected function setUp(): void
    {
        $this->orderManagementApi = $this->createMock(OrderManagementInterface::class);

        $this->reservationFormat = new ReservationFormat(
            $this->orderManagementApi,
            new LineReference(),
            $this->createMock(LogManager::class)
        );
    }

    /**
     * An order placed from 1.7.42 on already says which format it was reserved with, so nothing
     * is fetched and the stamp it carries stands.
     */
    public function testAStampedOrderIsNotAskedAgain(): void
    {
        $payment = $this->buildPayment(true);
        $payment->expects(self::never())->method('setAdditionalInformation');
        $this->orderManagementApi->expects(self::never())->method('getOrder');

        $this->reservationFormat->stamp($this->buildOrder($payment, [[519, 'Kanalplast']]), self::QLIRO_ORDER_ID);
    }

    /**
     * An order placed before 1.7.0 was reserved with the cart item id in front of the sku. It
     * carries no stamp, and the reservation is what says so.
     */
    public function testAReservationCarryingTheItemIdIsStamped(): void
    {
        $payment = $this->buildPayment(null);
        $payment->expects(self::once())
            ->method('setAdditionalInformation')
            ->with(Config::QLIROONE_ADDITIONAL_INFO_LINE_REFERENCE_CARRIES_ITEM_ID, true);

        $this->expectReservation(['519:Kanalplast', 'unifaun']);

        $this->reservationFormat->stamp($this->buildOrder($payment, [[519, 'Kanalplast']]), self::QLIRO_ORDER_ID);
    }

    /**
     * An order placed between 1.7.0 and 1.7.41 was reserved with the sku alone, which is what the
     * capture of such an order has to keep sending.
     */
    public function testAReservationCarryingTheBareSkuIsStamped(): void
    {
        $payment = $this->buildPayment(null);
        $payment->expects(self::once())
            ->method('setAdditionalInformation')
            ->with(Config::QLIROONE_ADDITIONAL_INFO_LINE_REFERENCE_CARRIES_ITEM_ID, false);

        $this->expectReservation(['Kanalplast', 'unifaun']);

        $this->reservationFormat->stamp($this->buildOrder($payment, [[519, 'Kanalplast']]), self::QLIRO_ORDER_ID);
    }

    /**
     * The reservation is read with the credentials of the store the order was placed in, which on
     * a multi store merchant is not the ones the admin happens to be looking at.
     */
    public function testTheReservationIsReadForTheStoreTheOrderBelongsTo(): void
    {
        $this->orderManagementApi->expects(self::once())
            ->method('getOrder')
            ->with(self::QLIRO_ORDER_ID, 7)
            ->willReturn($this->buildQliroOrder(['Kanalplast']));

        $order = $this->buildOrder($this->buildPayment(null), [[519, 'Kanalplast']]);
        $order->method('getStoreId')->willReturn(7);

        $this->reservationFormat->stamp($order, self::QLIRO_ORDER_ID);
    }

    /**
     * A reservation that cannot be read leaves the order as this version already reads it, and the
     * next capture asks again. The capture goes to the same API, so it reports the outage itself.
     */
    public function testAReservationThatCannotBeReadLeavesTheOrderUnstamped(): void
    {
        $payment = $this->buildPayment(null);
        $payment->expects(self::never())->method('setAdditionalInformation');

        $this->orderManagementApi->method('getOrder')->willThrowException(new \RuntimeException('down'));

        $this->reservationFormat->stamp($this->buildOrder($payment, [[519, 'Kanalplast']]), self::QLIRO_ORDER_ID);
    }

    /**
     * A reservation whose lines stand for neither format is not guessed at, because Qliro refuses
     * a capture that disagrees with the reservation and refuses it for good.
     */
    public function testAReservationMatchingNothingLeavesTheOrderUnstamped(): void
    {
        $payment = $this->buildPayment(null);
        $payment->expects(self::never())->method('setAdditionalInformation');

        $this->expectReservation(['something-else', 'unifaun']);

        $this->reservationFormat->stamp($this->buildOrder($payment, [[519, 'Kanalplast']]), self::QLIRO_ORDER_ID);
    }

    /**
     * A sku that reads like a reference of the other format is not enough to tell them apart, so
     * a reservation answering both is treated as answering neither.
     */
    public function testAReservationAnsweringBothFormatsLeavesTheOrderUnstamped(): void
    {
        $payment = $this->buildPayment(null);
        $payment->expects(self::never())->method('setAdditionalInformation');

        $this->expectReservation(['519:Kanalplast', 'Kanalplast']);

        $this->reservationFormat->stamp(
            $this->buildOrder($payment, [[519, 'Kanalplast'], [520, 'Kanalplast']]),
            self::QLIRO_ORDER_ID
        );
    }

    /**
     * An order item with no cart item id behind it builds the same reference in both formats, so
     * it may only answer for the bare sku.
     */
    public function testAnItemWithoutACartItemIdDoesNotAnswerForTheItemIdFormat(): void
    {
        $payment = $this->buildPayment(null);
        $payment->expects(self::once())
            ->method('setAdditionalInformation')
            ->with(Config::QLIROONE_ADDITIONAL_INFO_LINE_REFERENCE_CARRIES_ITEM_ID, false);

        $this->expectReservation(['Kanalplast']);

        $this->reservationFormat->stamp($this->buildOrder($payment, [[null, 'Kanalplast']]), self::QLIRO_ORDER_ID);
    }

    /**
     * A Qliro order answered without any lines is an answer that matches nothing, not a reason to
     * abort the capture this class exists to let through.
     */
    public function testAReservationWithoutLinesLeavesTheOrderUnstamped(): void
    {
        $payment = $this->buildPayment(null);
        $payment->expects(self::never())->method('setAdditionalInformation');

        $qliroOrder = $this->createMock(AdminOrderInterface::class);
        $qliroOrder->method('getOrderItemActions')->willReturn(null);
        $this->orderManagementApi->method('getOrder')->willReturn($qliroOrder);

        $this->reservationFormat->stamp($this->buildOrder($payment, [[519, 'Kanalplast']]), self::QLIRO_ORDER_ID);
    }

    /**
     * A line the response carried no reference for, and an order item with no sku, would both
     * stand for the empty reference and so answer for each other. Counting that pair would make
     * the reservation below look like it holds both formats, and a readable answer would be
     * thrown away.
     */
    public function testAnEmptyReferenceIsNotAMatch(): void
    {
        $payment = $this->buildPayment(null);
        $payment->expects(self::once())
            ->method('setAdditionalInformation')
            ->with(Config::QLIROONE_ADDITIONAL_INFO_LINE_REFERENCE_CARRIES_ITEM_ID, true);

        $this->expectReservation(['', '519:Kanalplast']);

        $this->reservationFormat->stamp(
            $this->buildOrder($payment, [[518, ''], [519, 'Kanalplast']]),
            self::QLIRO_ORDER_ID
        );
    }

    /**
     * Without a payment there is nothing to stamp, and nothing to ask about either.
     */
    public function testAnOrderWithoutAPaymentIsLeftAlone(): void
    {
        $this->orderManagementApi->expects(self::never())->method('getOrder');

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn(null);

        $this->reservationFormat->stamp($order, self::QLIRO_ORDER_ID);
    }

    /**
     * @param string[] $references
     */
    private function expectReservation(array $references): void
    {
        $this->orderManagementApi->method('getOrder')->willReturn($this->buildQliroOrder($references));
    }

    /**
     * @param string[] $references
     */
    private function buildQliroOrder(array $references): AdminOrderInterface
    {
        $lines = [];

        foreach ($references as $reference) {
            $line = new OrderItemAction();
            $line->setMerchantReference($reference);
            $lines[] = $line;
        }

        $qliroOrder = $this->createMock(AdminOrderInterface::class);
        $qliroOrder->method('getOrderItemActions')->willReturn($lines);

        return $qliroOrder;
    }

    /**
     * @param bool|null $stamp
     * @return Payment&MockObject
     */
    private function buildPayment($stamp)
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')
            ->willReturnCallback(
                static fn($key = null) => $key === Config::QLIROONE_ADDITIONAL_INFO_LINE_REFERENCE_CARRIES_ITEM_ID
                    ? $stamp
                    : null
            );

        return $payment;
    }

    /**
     * @param Payment&MockObject $payment
     * @param array<int, array{0: int|null, 1: string}> $items
     * @return Order&MockObject
     */
    private function buildOrder($payment, array $items)
    {
        $orderItems = [];

        foreach ($items as [$quoteItemId, $sku]) {
            $orderItem = $this->createMock(OrderItem::class);
            $orderItem->method('getQuoteItemId')->willReturn($quoteItemId);
            $orderItem->method('getSku')->willReturn($sku);
            $orderItems[] = $orderItem;
        }

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getAllItems')->willReturn($orderItems);

        return $order;
    }
}
