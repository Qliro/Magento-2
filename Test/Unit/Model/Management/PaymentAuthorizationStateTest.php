<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Management;

use Magento\Directory\Model\Currency;
use Magento\Sales\Api\Data\TransactionInterface;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterfaceFactory;
use Qliro\QliroOne\Api\Data\QliroOrderInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\Payment as PaymentManagement;
use Qliro\QliroOne\Model\Order\StateStatus;
use Qliro\QliroOne\Model\QliroOrder\Admin\RepeatedCapture;
use Magento\Framework\Lock\LockManagerInterface;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\AddItemsToInvoiceBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\InvoiceMarkItemsAsShippedRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\ShipmentMarkItemsAsShippedRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\CaptureCoverage;
use Qliro\QliroOne\Model\QliroOrder\Admin\CaptureRefundAllocator;
use Qliro\QliroOne\Model\QliroOrder\Admin\SequentialRefundProcessor;
use Qliro\QliroOne\Model\QliroOrder\ReservationFormat;

/**
 * The state the authorization gives the order, a held order keeping its hold
 *
 * @see \Qliro\QliroOne\Model\Management\Payment::createPaymentTransaction
 */
class PaymentAuthorizationStateTest extends TestCase
{
    private OrderRepositoryInterface&MockObject $orderRepository;

    protected function setUp(): void
    {
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
    }

    /**
     * A placement retry on an order the merchant held with a status of their own
     */
    public function testAHeldOrderReturningToTheStateKeepsItsStatus(): void
    {
        $order = $this->order(Order::STATE_HOLDED, 'holded');
        $order->setHoldBeforeState(Order::STATE_PENDING_PAYMENT);
        $order->setHoldBeforeStatus('merchant_check');
        $this->orderRepository->expects(self::never())->method('save');

        $this->management()->createPaymentTransaction($order, $this->qliroOrder(), Order::STATE_PENDING_PAYMENT);

        self::assertSame(
            [Order::STATE_HOLDED, Order::STATE_PENDING_PAYMENT, 'merchant_check'],
            [$order->getState(), $order->getHoldBeforeState(), $order->getHoldBeforeStatus()]
        );
    }

    public function testAHeldOrderGetsTheNewStateToReturnTo(): void
    {
        $order = $this->order(Order::STATE_HOLDED, 'holded');
        $order->setHoldBeforeState(Order::STATE_PENDING_PAYMENT);
        $order->setHoldBeforeStatus('merchant_check');
        $this->orderRepository->expects(self::once())->method('save');

        $this->management()->createPaymentTransaction($order, $this->qliroOrder(), Order::STATE_NEW);

        self::assertSame(
            [Order::STATE_HOLDED, Order::STATE_NEW, 'pending'],
            [$order->getState(), $order->getHoldBeforeState(), $order->getHoldBeforeStatus()]
        );
    }

    public function testAnOrderNotHeldMovesToTheState(): void
    {
        $order = $this->order(Order::STATE_PENDING_PAYMENT, 'pending_payment');
        $this->orderRepository->expects(self::once())->method('save');

        $this->management()->createPaymentTransaction($order, $this->qliroOrder(), Order::STATE_NEW);

        self::assertSame([Order::STATE_NEW, 'pending'], [$order->getState(), $order->getStatus()]);
    }

    private function order(string $state, string $status): Order&MockObject
    {
        $config = $this->createMock(OrderConfig::class);
        $config->method('getStateStatuses')->willReturnCallback(fn (string $state): array => match ($state) {
            Order::STATE_NEW => ['pending'],
            Order::STATE_PENDING_PAYMENT => ['pending_payment', 'merchant_check'],
            default => [$state],
        });
        $config->method('getStateDefaultStatus')->willReturnCallback(
            fn (string $state): string => $state === Order::STATE_NEW ? 'pending' : $state
        );
        $currency = $this->createMock(Currency::class);
        $currency->method('formatTxt')->willReturn('100,00 kr');

        $order = $this->createPartialMock(Order::class, ['getConfig', 'getId', 'getPayment', 'getBaseCurrency']);
        $order->method('getConfig')->willReturn($config);
        $order->method('getId')->willReturn('42');
        $order->method('getPayment')->willReturn($this->createMock(Payment::class));
        $order->method('getBaseCurrency')->willReturn($currency);
        $order->setState($state);
        $order->setStatus($status);

        return $order;
    }

    private function qliroOrder(): QliroOrderInterface
    {
        $qliroOrder = $this->createMock(QliroOrderInterface::class);
        $qliroOrder->method('getOrderId')->willReturn(5478412);

        return $qliroOrder;
    }

    private function management(): PaymentManagement
    {
        $transactionBuilder = $this->createMock(BuilderInterface::class);
        foreach (['setPayment', 'setOrder', 'setTransactionId'] as $method) {
            $transactionBuilder->method($method)->willReturnSelf();
        }
        $transactionBuilder->method('build')->with(TransactionInterface::TYPE_AUTH)
            ->willReturn($this->createMock(Transaction::class));

        return new PaymentManagement(
            $this->createMock(Config::class),
            $this->createMock(OrderManagementInterface::class),
            $this->createMock(LinkRepositoryInterface::class),
            $this->orderRepository,
            $this->createMock(LogManager::class),
            $transactionBuilder,
            $this->createMock(OrderManagementStatusInterfaceFactory::class),
            $this->createMock(OrderManagementStatusRepositoryInterface::class),
            $this->createMock(InvoiceMarkItemsAsShippedRequestBuilder::class),
            $this->createMock(ShipmentMarkItemsAsShippedRequestBuilder::class),
            $this->createMock(AddItemsToInvoiceBuilder::class),
            $this->createMock(CaptureRefundAllocator::class),
            $this->createMock(SequentialRefundProcessor::class),
            $this->createMock(ReservationFormat::class),
            $this->createMock(CaptureCoverage::class),
            $this->createMock(OrderPaymentRepositoryInterface::class),
            $this->createMock(RepeatedCapture::class),
            $this->createMock(LockManagerInterface::class),
            new StateStatus($this->createMock(LogManager::class))
        );
    }
}
