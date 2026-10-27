<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Management;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use Magento\Sales\Model\Order\Status\History;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\CheckoutStatusInterface;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface;
use Qliro\QliroOne\Api\Data\QliroOrderInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\Payment as PaymentManagement;
use Qliro\QliroOne\Model\Management\PlaceOrder;
use Qliro\QliroOne\Model\Management\Quote as QuoteManagement;
use Qliro\QliroOne\Model\Order\OrderPlacer;
use Qliro\QliroOne\Model\Order\OrganizationNumber;
use Qliro\QliroOne\Model\Order\PlacedOrderFinder;
use Qliro\QliroOne\Model\QliroOrder\Builder\OrderItemsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromOrderConverter;
use Qliro\QliroOne\Model\QliroOrder\DiscountLines;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\LineDifferences;
use Qliro\QliroOne\Model\ResourceModel\Lock;
use Qliro\QliroOne\Service\RecurringPayments\Data as RecurringDataService;

/**
 * @see \Qliro\QliroOne\Model\Management\PlaceOrder::execute
 *
 * An order whose product lines differ from the Qliro order is placed on hold, and stays on hold
 * through the later status callbacks until the merchant releases it.
 */
class PlaceOrderLineDifferencesTest extends TestCase
{
    private const QLIRO_ORDER_ID = '900200';

    private LogManager&MockObject $logManager;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private OrderSender&MockObject $orderSender;
    private OrderPlacer&MockObject $orderPlacer;
    private OrderItemsBuilder&MockObject $orderItemsBuilder;
    private PlacedOrderFinder&MockObject $placedOrderFinder;
    private LinkInterface&MockObject $link;
    private Quote&MockObject $quote;
    private array $comments = [];

    protected function setUp(): void
    {
        $this->logManager = $this->createMock(LogManager::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderSender = $this->createMock(OrderSender::class);
        $this->orderPlacer = $this->createMock(OrderPlacer::class);
        $this->placedOrderFinder = $this->createMock(PlacedOrderFinder::class);

        $this->link = $this->createMock(LinkInterface::class);
        $this->link->method('getQliroOrderId')->willReturn(self::QLIRO_ORDER_ID);
        $this->link->method('getQuoteId')->willReturn(17);

        $this->quote = $this->createMock(Quote::class);
        $this->quote->method('getId')->willReturn(17);
        $this->quote->method('isVirtual')->willReturn(true);
        $this->quote->method('getPayment')->willReturn($this->createMock(QuotePayment::class));

        $this->orderItemsBuilder = $this->createMock(OrderItemsBuilder::class);
        $this->orderItemsBuilder->method('setQuote')->willReturnSelf();
    }

    /**
     * Validation passed on two lines, then one left the quote before the order was placed
     */
    public function testALineRemovedBeforePlacementPutsTheOrderOnHoldWithAComment(): void
    {
        $this->cartHolds([$this->line('518:SKU-A', 1)]);
        $order = $this->order();
        $this->orderPlacer->expects(self::once())->method('place')->willReturn($order);
        $this->logManager->expects(self::once())->method('critical')
            ->with(
                self::stringContains('differ'),
                self::callback(static fn (array $context): bool => count($context['extra']['cart_lines']) === 1
                    && count($context['extra']['qliro_lines']) === 3
                    && $context['extra']['differences'][0]['merchant_reference'] === '519:SKU-B')
            );

        $this->placeOrder()->execute($this->qliroOrder(CheckoutStatusInterface::STATUS_IN_PROCESS));

        self::assertSame(Order::STATE_HOLDED, $order->getState());
        self::assertSame('holded', $order->getStatus());
        self::assertSame(Order::STATE_PENDING_PAYMENT, $order->getHoldBeforeState());
        self::assertCount(1, $this->comments);
        self::assertStringContainsString('519:SKU-B: 0 in the order, 2 in Qliro (not in the cart)', $this->comments[0]);
    }

    public function testMatchingLinesPlaceTheOrderAsBefore(): void
    {
        $this->cartHolds([$this->line('518:SKU-A', 1), $this->line('519:SKU-B', 2)]);
        $order = $this->order();
        $this->orderPlacer->method('place')->willReturn($order);
        $this->logManager->expects(self::never())->method('critical');

        $this->placeOrder()->execute($this->qliroOrder(CheckoutStatusInterface::STATUS_COMPLETED));

        self::assertSame(Order::STATE_NEW, $order->getState());
        self::assertSame([], $this->comments);
    }

    /**
     * A Completed order on hold gets its state for when the hold is released
     */
    public function testACompletedOrderStaysOnHold(): void
    {
        $this->cartHolds([$this->line('518:SKU-A', 1)]);
        $order = $this->order();
        $this->orderPlacer->method('place')->willReturn($order);

        $this->placeOrder()->execute($this->qliroOrder(CheckoutStatusInterface::STATUS_COMPLETED));

        self::assertSame(Order::STATE_HOLDED, $order->getState());
        self::assertSame(Order::STATE_NEW, $order->getHoldBeforeState());
        self::assertSame('qliro_new', $order->getHoldBeforeStatus());
    }

    public function testARefusedOrderOnHoldIsReleasedAndDenied(): void
    {
        $this->cartHolds([$this->line('518:SKU-A', 1)]);
        $payment = $this->createMock(OrderPayment::class);
        $order = $this->order($payment);
        $this->orderPlacer->method('place')->willReturn($order);
        $deniedIn = null;
        $payment->expects(self::once())->method('deny')
            ->willReturnCallback(function () use ($order, $payment, &$deniedIn) {
                $deniedIn = $order->getState();

                return $payment;
            });

        $this->placeOrder()->execute($this->qliroOrder(CheckoutStatusInterface::STATUS_REFUSED));

        // Magento cancels only an order that is not on hold
        self::assertSame(Order::STATE_PENDING_PAYMENT, $deniedIn);
    }

    /**
     * An earlier attempt committed the order and failed before it was held
     */
    public function testAnAdoptedOrderIsHeldToo(): void
    {
        $this->cartHolds([$this->line('518:SKU-A', 1)]);
        $order = $this->order();
        $this->placedOrderFinder->method('find')->willReturn($order);
        $this->orderPlacer->expects(self::never())->method('place');

        $this->placeOrder()->execute($this->qliroOrder(CheckoutStatusInterface::STATUS_IN_PROCESS));

        self::assertSame(Order::STATE_HOLDED, $order->getState());
        self::assertCount(1, $this->comments);
    }

    /**
     * The order is paid, a comparison that fails is logged and the order placed
     */
    public function testAComparisonThatFailsDoesNotStopThePlacement(): void
    {
        $this->orderItemsBuilder->method('create')->willThrowException(new \RuntimeException('observer failed'));
        $order = $this->order();
        $this->orderPlacer->expects(self::once())->method('place')->willReturn($order);
        $this->logManager->expects(self::once())->method('critical')->with(self::stringContains('Could not compare'));
        $this->link->expects(self::once())->method('setOrderId')->with(31);

        self::assertSame($order, $this->placeOrder()->execute($this->qliroOrder(CheckoutStatusInterface::STATUS_IN_PROCESS)));
        self::assertSame(Order::STATE_PENDING_PAYMENT, $order->getState());
    }

    /**
     * Held by an earlier attempt and released by the merchant since, a retry leaves it released
     */
    public function testAnOrderHeldBeforeIsNotHeldAgain(): void
    {
        $this->cartHolds([$this->line('518:SKU-A', 1)]);
        $payment = $this->createMock(OrderPayment::class);
        $payment->method('getAdditionalInformation')->willReturnMap([['qliro_lines_differ', true]]);
        $order = $this->order($payment);
        $this->placedOrderFinder->method('find')->willReturn($order);

        $this->placeOrder()->execute($this->qliroOrder(CheckoutStatusInterface::STATUS_IN_PROCESS));

        self::assertSame(Order::STATE_PENDING_PAYMENT, $order->getState());
        self::assertSame([], $this->comments);
    }

    /**
     * As in the validate callback, a Qliro order without lines is nothing to compare with
     */
    public function testAQliroOrderWithoutLinesIsNotCompared(): void
    {
        $this->cartHolds([$this->line('518:SKU-A', 1)]);
        $order = $this->order();
        $this->orderPlacer->method('place')->willReturn($order);
        $this->link->method('getQliroOrderStatus')->willReturn(CheckoutStatusInterface::STATUS_IN_PROCESS);
        $qliroOrder = $this->createMock(QliroOrderInterface::class);
        $qliroOrder->method('getOrderId')->willReturn(self::QLIRO_ORDER_ID);
        $qliroOrder->method('getCustomerCheckoutStatus')->willReturn(CheckoutStatusInterface::STATUS_IN_PROCESS);
        $qliroOrder->method('getCustomer')->willReturn($this->createMock(QliroOrderCustomerInterface::class));
        $qliroOrder->method('getOrderItems')->willReturn([]);

        $this->placeOrder()->execute($qliroOrder);

        self::assertSame(Order::STATE_PENDING_PAYMENT, $order->getState());
        self::assertSame([], $this->comments);
    }

    public function testAPriceDifferenceNamesBothPrices(): void
    {
        $line = $this->line('519:SKU-B', 2);
        $line->setPricePerItemIncVat(99.0);
        $this->cartHolds([$this->line('518:SKU-A', 1), $line]);
        $order = $this->order();
        $this->orderPlacer->method('place')->willReturn($order);

        $this->placeOrder()->execute($this->qliroOrder(CheckoutStatusInterface::STATUS_IN_PROCESS));

        self::assertStringContainsString('519:SKU-B: 2 in the order, 2 in Qliro (price), 99 in the order, 125 in Qliro', $this->comments[0]);
    }

    /**
     * The order is paid, a hold that cannot be saved still lets the link get the order
     */
    public function testAHoldThatCannotBeSavedStillLinksTheOrder(): void
    {
        $this->cartHolds([$this->line('518:SKU-A', 1)]);
        $this->orderRepository->method('save')->willThrowException(new \RuntimeException('Lock wait timeout exceeded'));
        $this->orderPlacer->method('place')->willReturn($this->order());
        $this->link->expects(self::once())->method('setOrderId')->with(31);

        $this->placeOrder()->execute($this->qliroOrder(CheckoutStatusInterface::STATUS_IN_PROCESS));
    }

    /**
     * The confirmation would list other items than the customer paid for
     */
    public function testACompletedOrderHeldForItsLinesGetsNoConfirmation(): void
    {
        $this->cartHolds([$this->line('518:SKU-A', 1)]);
        $info = [];
        $payment = $this->createMock(OrderPayment::class);
        $payment->method('setAdditionalInformation')->willReturnCallback(
            function ($key, $value = null) use (&$info, $payment) {
                is_array($key) ? $info = $key : $info[$key] = $value;

                return $payment;
            }
        );
        $payment->method('getAdditionalInformation')->willReturnCallback(
            function ($key = null) use (&$info) {
                return $key === null ? $info : ($info[$key] ?? null);
            }
        );
        $order = $this->order($payment);
        $order->setCanSendNewEmailFlag(true);
        $this->orderPlacer->method('place')->willReturn($order);
        $this->orderSender->expects(self::never())->method('send');

        $this->placeOrder()->execute($this->qliroOrder(CheckoutStatusInterface::STATUS_COMPLETED));

        self::assertSame(Order::STATE_HOLDED, $order->getState());
    }

    /**
     * @param QliroOrderItemInterface[] $lines
     */
    private function cartHolds(array $lines): void
    {
        $this->orderItemsBuilder->method('create')->willReturn($lines);
    }

    private function qliroOrder(string $status): QliroOrderInterface&MockObject
    {
        $this->link->method('getQliroOrderStatus')->willReturn($status);

        $qliroOrder = $this->createMock(QliroOrderInterface::class);
        $qliroOrder->method('getOrderId')->willReturn(self::QLIRO_ORDER_ID);
        $qliroOrder->method('getCustomerCheckoutStatus')->willReturn($status);
        $qliroOrder->method('getCustomer')->willReturn($this->createMock(QliroOrderCustomerInterface::class));
        $qliroOrder->method('getOrderItems')->willReturn([
            $this->line('518:SKU-A', 1),
            $this->line('519:SKU-B', 2),
            $this->line('flatrate_flatrate', 1, QliroOrderItemInterface::TYPE_SHIPPING),
        ]);

        return $qliroOrder;
    }

    private function line(string $reference, float $quantity, string $type = QliroOrderItemInterface::TYPE_PRODUCT): Item
    {
        $line = new Item();
        $line->setMerchantReference($reference);
        $line->setType($type);
        $line->setQuantity($quantity);
        $line->setPricePerItemIncVat(125.0);
        $line->setPricePerItemExVat(100.0);

        return $line;
    }

    /**
     * A real order for its state and hold fields, its collaborators stubbed
     */
    private function order(?OrderPayment $payment = null): Order&MockObject
    {
        $config = $this->createMock(OrderConfig::class);
        $config->method('getStateDefaultStatus')->willReturnMap([
            [Order::STATE_HOLDED, 'holded'],
            [Order::STATE_PENDING_PAYMENT, 'pending_payment'],
            [Order::STATE_PAYMENT_REVIEW, 'payment_review'],
        ]);

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConfig', 'addCommentToStatusHistory', 'getId', 'getIncrementId', 'getPayment'])
            ->getMock();
        $order->method('getConfig')->willReturn($config);
        $order->method('getId')->willReturn(31);
        $order->method('getIncrementId')->willReturn('300000031');
        $order->method('getPayment')->willReturn($payment ?? $this->createMock(OrderPayment::class));
        $order->method('addCommentToStatusHistory')
            ->willReturnCallback(function ($comment) {
                $this->comments[] = (string)$comment;

                return $this->createMock(History::class);
            });
        $order->setState(Order::STATE_PENDING_PAYMENT);
        $order->setStatus('pending_payment');

        return $order;
    }

    private function placeOrder(): PlaceOrder
    {
        $quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $quoteRepository->method('get')->willReturn($this->quote);

        $quoteManagement = $this->createMock(QuoteManagement::class);
        $quoteManagement->method('setQuote')->willReturnSelf();

        $linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $linkRepository->method('getByQliroOrderId')->willReturn($this->link);

        $config = $this->createMock(Config::class);
        $config->method('getOrderStatus')->willReturn('qliro_new');
        $config->method('isUseIncrementIdAsReference')->willReturn(true);

        return new PlaceOrder(
            $config,
            $this->createMock(MerchantInterface::class),
            $this->createMock(OrderManagementInterface::class),
            $this->createMock(QuoteFromOrderConverter::class),
            $linkRepository,
            $quoteRepository,
            $this->orderRepository,
            $this->createMock(ContainerMapper::class),
            $this->logManager,
            $this->orderPlacer,
            $this->createMock(Lock::class),
            $this->orderSender,
            $quoteManagement,
            $this->createMock(PaymentManagement::class),
            $this->createMock(RecurringDataService::class),
            $this->createMock(OrganizationNumber::class),
            null,
            $this->placedOrderFinder,
            $this->createMock(DiscountLines::class),
            $this->orderItemsBuilder,
            new LineDifferences()
        );
    }

}
