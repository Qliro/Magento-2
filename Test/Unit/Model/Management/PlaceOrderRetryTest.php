<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Management;

use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment as QuotePayment;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\CheckoutStatusInterface;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface;
use Qliro\QliroOne\Api\Data\QliroOrderInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\Payment as PaymentManagement;
use Qliro\QliroOne\Model\Management\PlaceOrder;
use Qliro\QliroOne\Model\Management\Quote as QuoteManagement;
use Qliro\QliroOne\Model\Order\OrderPlacer;
use Qliro\QliroOne\Model\Order\OrganizationNumber;
use Qliro\QliroOne\Model\Order\PlacedOrderFinder;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromOrderConverter;
use Qliro\QliroOne\Model\ResourceModel\Lock;
use Qliro\QliroOne\Service\RecurringPayments\Data as RecurringDataService;

/**
 * @see \Qliro\QliroOne\Model\Management\PlaceOrder::execute
 * @see \Qliro\QliroOne\Model\Management\PlaceOrder::poll
 *
 * A failed placement must leave the paid Qliro order reachable for the next attempt, and that
 * attempt must finish an order an earlier one committed instead of placing a second one.
 */
class PlaceOrderRetryTest extends TestCase
{
    private const QLIRO_ORDER_ID = '900100';
    private const QUOTE_ID = 17;

    private LinkRepositoryInterface&MockObject $linkRepository;
    private OrderPlacer&MockObject $orderPlacer;
    private PaymentManagement&MockObject $paymentManagement;
    private PlacedOrderFinder&MockObject $placedOrderFinder;
    private QuoteFromOrderConverter&MockObject $quoteFromOrderConverter;
    private MerchantInterface&MockObject $merchantApi;
    private Lock&MockObject $lock;
    private LinkInterface&MockObject $link;
    private QliroOrderInterface&MockObject $qliroOrder;
    private Quote&MockObject $quote;

    protected function setUp(): void
    {
        $this->linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $this->orderPlacer = $this->createMock(OrderPlacer::class);
        $this->paymentManagement = $this->createMock(PaymentManagement::class);
        $this->placedOrderFinder = $this->createMock(PlacedOrderFinder::class);
        $this->quoteFromOrderConverter = $this->createMock(QuoteFromOrderConverter::class);
        $this->merchantApi = $this->createMock(MerchantInterface::class);
        $this->lock = $this->createMock(Lock::class);

        $this->link = $this->createMock(LinkInterface::class);
        $this->link->method('getQliroOrderId')->willReturn(self::QLIRO_ORDER_ID);
        $this->link->method('getQuoteId')->willReturn(self::QUOTE_ID);
        $this->link->method('getQliroOrderStatus')->willReturn(CheckoutStatusInterface::STATUS_IN_PROCESS);
        $this->linkRepository->method('getByQliroOrderId')->willReturn($this->link);

        $this->qliroOrder = $this->createMock(QliroOrderInterface::class);
        $this->qliroOrder->method('getOrderId')->willReturn(self::QLIRO_ORDER_ID);
        $this->qliroOrder->method('getCustomerCheckoutStatus')->willReturn(CheckoutStatusInterface::STATUS_IN_PROCESS);
        $this->qliroOrder->method('getOrderItems')->willReturn([]);
        $this->qliroOrder->method('getCustomer')
            ->willReturn($this->createMock(QliroOrderCustomerInterface::class));

        $this->quote = $this->createMock(Quote::class);
        $this->quote->method('getId')->willReturn(self::QUOTE_ID);
        $this->quote->method('isVirtual')->willReturn(true);
        $this->quote->method('getPayment')->willReturn($this->createMock(QuotePayment::class));
    }

    public function testFailedPlacementKeepsTheLinkActive(): void
    {
        $this->orderPlacer->method('place')
            ->willThrowException(new CouldNotSaveException(__('A server error stopped your order')));

        $this->link->expects(self::never())->method('setIsActive');
        $this->link->expects(self::never())->method('setOrderId');
        $this->link->expects(self::once())->method('setMessage')->with('A server error stopped your order');
        $this->linkRepository->expects(self::once())->method('save')->with($this->link);

        $this->expectException(TerminalException::class);
        $this->placeOrder()->execute($this->qliroOrder);
    }

    public function testAnOrderAnEarlierAttemptCommittedIsLinkedNotPlacedAgain(): void
    {
        $order = $this->order(31);
        $this->placedOrderFinder->expects(self::once())->method('find')
            ->with(self::QUOTE_ID, self::QLIRO_ORDER_ID)
            ->willReturn($order);

        $this->orderPlacer->expects(self::never())->method('place');
        $this->quoteFromOrderConverter->expects(self::never())->method('convert');
        $this->paymentManagement->expects(self::once())->method('createPaymentTransaction')
            ->with($order, $this->qliroOrder);
        $this->link->expects(self::once())->method('setOrderId')->with(31);

        self::assertSame($order, $this->placeOrder()->execute($this->qliroOrder));
    }

    public static function finishedStates(): array
    {
        return [
            'canceled' => [Order::STATE_CANCELED],
            'closed' => [Order::STATE_CLOSED],
            'complete' => [Order::STATE_COMPLETE],
        ];
    }

    /**
     * Canceled offline or by the cron while it had no link, so a retry must not bring it back
     *
     * @dataProvider finishedStates
     */
    public function testAFinishedOrderAnEarlierAttemptCommittedIsNotRevived(string $state): void
    {
        $order = $this->order(35);
        $order->method('getState')->willReturn($state);
        $this->placedOrderFinder->method('find')->willReturn($order);

        $this->orderPlacer->expects(self::never())->method('place');
        $this->paymentManagement->expects(self::never())->method('createPaymentTransaction');
        $this->link->expects(self::never())->method('setOrderId');

        $this->expectException(TerminalException::class);
        $this->placeOrder()->execute($this->qliroOrder);
    }

    /**
     * A retry finishes only an order whose link has no order id, so the authorization has to
     * exist before the link gets it
     */
    public function testThePaymentTransactionIsSavedBeforeTheLinkGetsTheOrderId(): void
    {
        $this->orderPlacer->method('place')->willReturn($this->order(36));

        $calls = [];
        $this->paymentManagement->method('createPaymentTransaction')
            ->willReturnCallback(static function () use (&$calls): void {
                $calls[] = 'transaction';
            });
        $this->link->method('setOrderId')
            ->willReturnCallback(function () use (&$calls) {
                $calls[] = 'order id';

                return $this->link;
            });

        $this->placeOrder()->execute($this->qliroOrder);

        self::assertSame(['transaction', 'order id'], $calls);
    }

    public function testPlacesTheOrderWhenNoEarlierAttemptCommittedOne(): void
    {
        $order = $this->order(32);
        $this->placedOrderFinder->method('find')->willReturn(null);

        $this->quoteFromOrderConverter->expects(self::once())->method('convert');
        $this->orderPlacer->expects(self::once())->method('place')->with($this->quote)->willReturn($order);
        $this->link->expects(self::once())->method('setOrderId')->with(32);

        self::assertSame($order, $this->placeOrder()->execute($this->qliroOrder));
    }

    /**
     * A retry only finishes an order whose link has no order id yet, so the link must not get one
     * before the authorization a cancel needs to reach Qliro exists
     */
    public function testLinkGetsNoOrderIdWhenThePaymentTransactionFails(): void
    {
        $this->orderPlacer->method('place')->willReturn($this->order(33));
        $this->paymentManagement->method('createPaymentTransaction')
            ->willThrowException(new \RuntimeException('Lock wait timeout exceeded'));

        $this->link->expects(self::never())->method('setOrderId');
        $this->link->expects(self::never())->method('setIsActive');

        $this->expectException(TerminalException::class);
        $this->placeOrder()->execute($this->qliroOrder);
    }

    public function testPlacesWithoutTheFinderForTheOldSignature(): void
    {
        $order = $this->order(34);
        $this->orderPlacer->expects(self::once())->method('place')->willReturn($order);

        self::assertSame($order, $this->placeOrder(false)->execute($this->qliroOrder));
    }

    public function testPollReleasesTheLockOnAPhpError(): void
    {
        $pollLink = $this->createMock(LinkInterface::class);
        $pollLink->method('getQliroOrderId')->willReturn(self::QLIRO_ORDER_ID);
        $pollLink->method('getOrderId')->willReturn(null);
        $this->linkRepository->method('getByQuoteId')->willReturn($pollLink);

        $completed = $this->createMock(QliroOrderInterface::class);
        $completed->method('getOrderId')->willReturn(self::QLIRO_ORDER_ID);
        $completed->method('getCustomerCheckoutStatus')->willReturn(CheckoutStatusInterface::STATUS_COMPLETED);
        $this->merchantApi->method('getOrder')->willReturn($completed);

        $linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $linkRepository->method('getByQuoteId')->willReturn($pollLink);
        $linkRepository->method('getByQliroOrderId')->willThrowException(new \TypeError('null given'));
        $this->linkRepository = $linkRepository;

        $this->lock->method('lock')->willReturn(true);
        $this->lock->expects(self::once())->method('unlock')->with(self::QLIRO_ORDER_ID);

        $this->expectException(TerminalException::class);
        $this->placeOrder()->setQuote($this->quote)->poll();
    }

    private function order(int $id): Order&MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($id);
        $order->method('getIncrementId')->willReturn('30000000' . $id);

        return $order;
    }

    private function placeOrder(bool $withFinder = true): PlaceOrder
    {
        $quoteRepository = $this->createMock(CartRepositoryInterface::class);
        $quoteRepository->method('get')->willReturn($this->quote);

        $quoteManagement = $this->createMock(QuoteManagement::class);
        $quoteManagement->method('setQuote')->willReturnSelf();

        return new PlaceOrder(
            $this->createMock(Config::class),
            $this->merchantApi,
            $this->createMock(OrderManagementInterface::class),
            $this->quoteFromOrderConverter,
            $this->linkRepository,
            $quoteRepository,
            $this->createMock(OrderRepositoryInterface::class),
            $this->createMock(ContainerMapper::class),
            $this->createMock(LogManager::class),
            $this->orderPlacer,
            $this->lock,
            $this->createMock(OrderSender::class),
            $quoteManagement,
            $this->paymentManagement,
            $this->createMock(RecurringDataService::class),
            $this->createMock(OrganizationNumber::class),
            null,
            $withFinder ? $this->placedOrderFinder : null
        );
    }
}
