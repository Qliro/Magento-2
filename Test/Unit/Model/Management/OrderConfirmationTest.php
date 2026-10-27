<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Management;

use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\AdminUpdateMerchantReferenceRequestInterface;
use Qliro\QliroOne\Api\Data\CheckoutStatusInterface;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\Payment as PaymentManagement;
use Qliro\QliroOne\Model\Management\PlaceOrder;
use Qliro\QliroOne\Model\Management\PlaceRecurringOrder;
use Qliro\QliroOne\Model\Management\Quote as QuoteManagement;
use Qliro\QliroOne\Model\Order\OrderPlacer;
use Qliro\QliroOne\Model\Order\OrganizationNumber;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromOrderConverter;
use Qliro\QliroOne\Model\QliroOrder\Converter\RecurringQuoteFromOrderConverter;
use Qliro\QliroOne\Model\QliroOrder\DiscountLines;
use Qliro\QliroOne\Model\ResourceModel\Lock;
use Qliro\QliroOne\Observer\HoldOrderConfirmation;
use Qliro\QliroOne\Service\RecurringPayments\Data as RecurringDataService;

/**
 * The confirmation is held at placement and sent once, when Qliro reports the order Completed.
 *
 * @see \Qliro\QliroOne\Model\Management\PlaceOrder::applyQliroOrderStatus
 * @see \Qliro\QliroOne\Model\Management\PlaceRecurringOrder::applyQliroOrderStatus
 * @see \Qliro\QliroOne\Observer\HoldOrderConfirmation
 */
class OrderConfirmationTest extends TestCase
{
    private const COMPLETED = CheckoutStatusInterface::STATUS_COMPLETED;
    private const IN_PROCESS = CheckoutStatusInterface::STATUS_IN_PROCESS;
    private const ONHOLD = CheckoutStatusInterface::STATUS_ONHOLD;
    private const REFUSED = CheckoutStatusInterface::STATUS_REFUSED;

    private LinkRepositoryInterface&MockObject $linkRepository;
    private OrderSender&MockObject $orderSender;
    private bool $emailSent = false;

    public static function flows(): array
    {
        return [
            'completed at placement' => [[self::COMPLETED], 1],
            'in process then completed' => [[self::IN_PROCESS, self::COMPLETED], 1],
            'completed callback repeated' => [[self::COMPLETED, self::COMPLETED], 1],
            'on hold then completed' => [[self::ONHOLD, self::COMPLETED], 1],
            'on hold then refused' => [[self::ONHOLD, self::REFUSED], 0],
            'in process only' => [[self::IN_PROCESS], 0],
            'refused at placement' => [[self::REFUSED], 0],
        ];
    }

    #[DataProvider('flows')]
    public function testCheckoutSendsTheConfirmationOnceCompleted(array $statuses, int $sent): void
    {
        $this->expectSends($sent);
        $placeOrder = $this->placeOrder();

        // Held by the observer at placement, a later callback loads the order afresh
        foreach ($statuses as $index => $status) {
            $order = $index === 0 ? $this->order(false, true) : $this->order(true, false);
            $placeOrder->applyQliroOrderStatus($order, $this->link($status));
        }
    }

    #[DataProvider('flows')]
    public function testSubscriptionSendsTheConfirmationOnceCompleted(array $statuses, int $sent): void
    {
        $this->expectSends($sent);
        $placeRecurringOrder = $this->placeRecurringOrder();
        // Loaded again right after placement, so it never carries the hold
        $order = $this->order(true, false);

        // The subscription path loads the link by order id
        $this->linkRepository->method('getByOrderId')->willReturnOnConsecutiveCalls(
            ...array_map(fn (string $status) => $this->link($status), $statuses)
        );

        foreach ($statuses as $ignored) {
            $placeRecurringOrder->applyQliroOrderStatus($order);
        }
    }

    /**
     * Another extension turned the flag off, the module leaves its decision alone.
     */
    public function testLeavesAConfirmationAnotherExtensionHeldAlone(): void
    {
        $this->expectSends(0);

        $this->placeOrder()->applyQliroOrderStatus($this->order(false, false), $this->link(self::COMPLETED));
    }

    protected function setUp(): void
    {
        $this->linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $this->orderSender = $this->createMock(OrderSender::class);
        $this->emailSent = false;
    }

    private function expectSends(int $times): void
    {
        $this->orderSender->expects(self::exactly($times))->method('send')
            ->willReturnCallback(function (): bool {
                $this->emailSent = true;

                return true;
            });
    }

    /**
     * The sent state is shared, as the database shares it between loads of one order.
     */
    private function order(bool $canSend, bool $held): Order&MockObject
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturn(['qliroone_updated_merchant_reference' => true]);

        $config = $this->createMock(OrderConfig::class);
        $config->method('getStateDefaultStatus')->willReturn('payment_review');

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(15);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('3000000015');
        $order->method('getPayment')->willReturn($payment);
        $order->method('getConfig')->willReturn($config);
        $order->method('getCanSendNewEmailFlag')->willReturn($canSend);
        $order->method('getData')->willReturnCallback(
            static fn ($key = '') => $key === HoldOrderConfirmation::HELD ? $held : null
        );
        $order->method('getEmailSent')->willReturnCallback(fn (): bool => $this->emailSent);
        $order->method('addCommentToStatusHistory')
            ->willReturn($this->createMock(OrderStatusHistoryInterface::class));

        return $order;
    }

    private function link(string $status): LinkInterface&MockObject
    {
        $link = $this->createMock(LinkInterface::class);
        $link->method('getQliroOrderStatus')->willReturn($status);
        $link->method('getQliroOrderId')->willReturn('275174114');
        $link->method('getOrderId')->willReturn(15);

        return $link;
    }

    private function placeOrder(): PlaceOrder
    {
        return new PlaceOrder(
            $this->config(),
            $this->createMock(MerchantInterface::class),
            $this->createMock(OrderManagementInterface::class),
            $this->createMock(QuoteFromOrderConverter::class),
            $this->linkRepository,
            $this->createMock(CartRepositoryInterface::class),
            $this->createMock(OrderRepositoryInterface::class),
            $this->containerMapper(),
            $this->createMock(LogManager::class),
            $this->createMock(OrderPlacer::class),
            $this->createMock(Lock::class),
            $this->orderSender,
            $this->createMock(QuoteManagement::class),
            $this->createMock(PaymentManagement::class),
            $this->createMock(RecurringDataService::class),
            $this->createMock(OrganizationNumber::class),
            discountLines: $this->createMock(DiscountLines::class)
        );
    }

    private function placeRecurringOrder(): PlaceRecurringOrder
    {
        return new PlaceRecurringOrder(
            $this->config(),
            $this->createMock(MerchantInterface::class),
            $this->createMock(OrderManagementInterface::class),
            $this->createMock(RecurringQuoteFromOrderConverter::class),
            $this->linkRepository,
            $this->createMock(CartRepositoryInterface::class),
            $this->createMock(OrderRepositoryInterface::class),
            $this->containerMapper(),
            $this->createMock(LogManager::class),
            $this->createMock(OrderPlacer::class),
            $this->createMock(Lock::class),
            $this->orderSender,
            $this->createMock(QuoteManagement::class),
            $this->createMock(PaymentManagement::class),
            $this->createMock(RecurringDataService::class),
            $this->createMock(CartManagementInterface::class),
            $this->createMock(Order::class),
            $this->createMock(OrganizationNumber::class),
            discountLines: $this->createMock(DiscountLines::class)
        );
    }

    private function config(): Config&MockObject
    {
        $config = $this->createMock(Config::class);
        $config->method('getOrderStatus')->willReturn('pending');

        return $config;
    }

    private function containerMapper(): ContainerMapper&MockObject
    {
        $mapper = $this->createMock(ContainerMapper::class);
        $mapper->method('fromArray')
            ->willReturn($this->createMock(AdminUpdateMerchantReferenceRequestInterface::class));

        return $mapper;
    }
}
