<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Management;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\CheckoutStatusInterface;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\Payment as PaymentManagement;
use Qliro\QliroOne\Model\Management\PlaceOrder;
use Qliro\QliroOne\Model\Management\Quote as QuoteManagement;
use Qliro\QliroOne\Model\Order\OrderPlacer;
use Qliro\QliroOne\Model\Order\OrganizationNumber;
use Qliro\QliroOne\Model\QliroOrder\DiscountLines;
use Qliro\QliroOne\Model\QliroOrder\MerchantReference;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromOrderConverter;
use Qliro\QliroOne\Model\ResourceModel\Lock;
use Qliro\QliroOne\Service\RecurringPayments\Data as RecurringDataService;
use Qliro\QliroOne\Model\Order\StateStatus;

/**
 * @see \Qliro\QliroOne\Model\Management\PlaceOrder::applyQliroOrderStatus
 *
 * PLIN-373: the merchant-reference update must target the Qliro order the caller resolved (not a
 * link re-loaded by Magento order id, which can be a reused/foreign row).
 */
class PlaceOrderTest extends TestCase
{
    private Config&MockObject $qliroConfig;
    private OrderManagementInterface&MockObject $orderManagementApi;
    private LinkRepositoryInterface&MockObject $linkRepository;
    private ContainerMapper&MockObject $containerMapper;
    private LogManager&MockObject $logManager;
    private MerchantInterface&MockObject $merchantApi;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private MerchantReference&MockObject $merchantReference;
    private PlaceOrder $placeOrder;

    protected function setUp(): void
    {
        $this->qliroConfig = $this->createMock(Config::class);
        $this->orderManagementApi = $this->createMock(OrderManagementInterface::class);
        $this->linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $this->containerMapper = $this->createMock(ContainerMapper::class);
        $this->logManager = $this->createMock(LogManager::class);
        $this->merchantApi = $this->createMock(MerchantInterface::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->merchantReference = $this->createMock(MerchantReference::class);

        $this->qliroConfig->method('getOrderStatus')->willReturn('processing');
        $this->placeOrder = new PlaceOrder(
            $this->qliroConfig,
            $this->merchantApi,
            $this->orderManagementApi,
            $this->createMock(QuoteFromOrderConverter::class),
            $this->linkRepository,
            $this->createMock(CartRepositoryInterface::class),
            $this->orderRepository,
            $this->containerMapper,
            $this->logManager,
            $this->createMock(OrderPlacer::class),
            $this->createMock(Lock::class),
            $this->createMock(OrderSender::class),
            $this->createMock(QuoteManagement::class),
            $this->createMock(PaymentManagement::class),
            $this->createMock(RecurringDataService::class),
            $this->createMock(OrganizationNumber::class),
            discountLines: $this->createMock(DiscountLines::class),
            stateStatus: $this->createMock(StateStatus::class),
            merchantReference: $this->merchantReference
        );
    }

    /**
     * The update targets the Qliro order of the link the caller resolved, not one reloaded by order id
     */
    public function testCompletedSendsTheReferenceForThePassedLink(): void
    {
        $this->linkRepository->expects(self::never())->method('getByOrderId');
        $order = $this->completedOrder($this->createMock(Payment::class));
        $link = $this->completedLink('275174114');
        $this->merchantReference->expects(self::once())->method('sendOnce')->with($order, $link);

        self::assertTrue($this->placeOrder->applyQliroOrderStatus($order, $link));
    }

    /**
     * PLIN-390: the order can be placed by the checkoutStatus callback while the buyer is still at
     * Qliro. When they come back, `poll()` has to hand out that order rather than ask Qliro again,
     * because it is the return value the success page writes its session keys from, and the
     * tracking on that page has no other way to learn the order.
     */
    public function testPollReturnsTheOrderTheCallbackAlreadyPlaced(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(17);
        $this->linkRepository->method('getByQuoteId')->with(17)->willReturn($this->completedLink());

        $order = $this->createMock(Order::class);
        $this->orderRepository->method('get')->with(15)->willReturn($order);
        $this->merchantApi->expects(self::never())->method('getOrder');

        self::assertSame($order, $this->placeOrder->setQuote($quote)->poll());
    }

    private function completedLink(string $qliroOrderId = '275174114'): LinkInterface&MockObject
    {
        $link = $this->createMock(LinkInterface::class);
        $link->method('getQliroOrderStatus')->willReturn(CheckoutStatusInterface::STATUS_COMPLETED);
        $link->method('getQliroOrderId')->willReturn($qliroOrderId);
        $link->method('getOrderId')->willReturn(15);

        return $link;
    }

    private function completedOrder(Payment&MockObject $payment): Order&MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(15);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('3000000015');
        $order->method('getCanSendNewEmailFlag')->willReturn(false);
        $order->method('getPayment')->willReturn($payment);

        return $order;
    }
}
