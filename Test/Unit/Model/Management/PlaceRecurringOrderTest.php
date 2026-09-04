<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Management;

use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\AdminOrderInterface;
use Qliro\QliroOne\Api\Data\AdminOrderPaymentTransactionInterface;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\Data\QliroOrderPaymentMethodInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\Payment as PaymentManagement;
use Qliro\QliroOne\Model\Management\PlaceRecurringOrder;
use Qliro\QliroOne\Model\Management\Quote as QuoteManagement;
use Qliro\QliroOne\Model\Order\OrderPlacer;
use Qliro\QliroOne\Model\QliroOrder\Converter\RecurringQuoteFromOrderConverter;
use Qliro\QliroOne\Model\ResourceModel\Lock;
use Qliro\QliroOne\Service\RecurringPayments\Data as RecurringDataService;

/**
 * A recurring order used to be stamped once per payment transaction, so the last one in the array
 * won, and its Type ("Preauthorization", "Capture") was written as if it were a payment method
 * code. One order's transactions can belong to different PSPs (PLIN-324), so the method now comes
 * from the order level PaymentMethod, which is final after routing.
 *
 * @see \Qliro\QliroOne\Model\Management\PlaceRecurringOrder
 */
class PlaceRecurringOrderTest extends TestCase
{
    private Payment&MockObject $payment;
    private PlaceRecurringOrder $placeRecurringOrder;

    protected function setUp(): void
    {
        $this->payment = $this->createMock(Payment::class);

        $quote = $this->createMock(Quote::class);
        $quote->method('getPayment')->willReturn($this->payment);

        $this->placeRecurringOrder = new PlaceRecurringOrder(
            $this->createMock(Config::class),
            $this->createMock(MerchantInterface::class),
            $this->createMock(OrderManagementInterface::class),
            $this->createMock(RecurringQuoteFromOrderConverter::class),
            $this->createMock(LinkRepositoryInterface::class),
            $this->createMock(CartRepositoryInterface::class),
            $this->createMock(OrderRepositoryInterface::class),
            $this->createMock(ContainerMapper::class),
            $this->createMock(LogManager::class),
            $this->createMock(OrderPlacer::class),
            $this->createMock(Lock::class),
            $this->createMock(OrderSender::class),
            $this->createMock(QuoteManagement::class),
            $this->createMock(PaymentManagement::class),
            $this->createMock(RecurringDataService::class),
            $this->createMock(CartManagementInterface::class),
            $this->createMock(Order::class)
        );
        $this->placeRecurringOrder->setQuote($quote);
    }

    /**
     * Several transactions of different types, and the order level method is the one stored.
     */
    public function testTakesTheMethodFromTheOrderRatherThanTheLastTransaction(): void
    {
        $paymentMethod = $this->createMock(QliroOrderPaymentMethodInterface::class);
        $paymentMethod->method('getPaymentTypeCode')->willReturn('QLIROPAYLATER_INVOICE30');
        $paymentMethod->method('getPaymentMethodName')->willReturn('Faktura 30 dagar');

        $stored = $this->stampedInformation($this->qliroOrder($paymentMethod, [
            $this->transaction('Preauthorization', 'QLIROPAYLATER_INVOICE30'),
            $this->transaction('Capture', 'CREDITCARDS'),
        ]));

        self::assertSame('QLIROPAYLATER_INVOICE30', $stored['qliro_payment_method_code']);
        self::assertSame('Faktura 30 dagar', $stored['qliro_payment_method_name']);
    }

    /**
     * Without an order level method the first transaction that names one is used, and nothing is
     * written for the code: a transaction Type is not a payment method.
     */
    public function testFallsBackToTheFirstTransactionThatNamesAMethod(): void
    {
        $stored = $this->stampedInformation($this->qliroOrder(null, [
            $this->transaction('Preauthorization', null),
            $this->transaction('Capture', 'QLIROPAYLATER_BNPL'),
            $this->transaction('Capture', 'CREDITCARDS'),
        ]));

        self::assertSame('QLIROPAYLATER_BNPL', $stored['qliro_payment_method_name']);
        self::assertArrayNotHasKey('qliro_payment_method_code', $stored);
    }

    /**
     * An order with neither stamps no method at all rather than a transaction type.
     */
    public function testStampsNoMethodWhenTheOrderNamesNone(): void
    {
        $stored = $this->stampedInformation($this->qliroOrder(null, [$this->transaction('Capture', null)]));

        self::assertArrayNotHasKey('qliro_payment_method_name', $stored);
        self::assertArrayNotHasKey('qliro_payment_method_code', $stored);
    }

    /**
     * The stamping is private and the rest of the placement talks to Magento and to Qliro, so it is
     * exercised directly rather than through execute().
     *
     * @return array<string, mixed>
     */
    private function stampedInformation(AdminOrderInterface $qliroOrder): array
    {
        $stored = [];
        $this->payment->method('setAdditionalInformation')
            ->willReturnCallback(function ($key, $value) use (&$stored) {
                $stored[$key] = $value;

                return $this->payment;
            });

        $link = $this->createMock(LinkInterface::class);
        $link->method('getQliroOrderId')->willReturn(5510203);
        $link->method('getReference')->willReturn('000000001');

        $method = new \ReflectionMethod(PlaceRecurringOrder::class, 'addAdditionalInfoToQuote');
        $method->invoke($this->placeRecurringOrder, $link, $qliroOrder);

        return $stored;
    }

    private function qliroOrder(?QliroOrderPaymentMethodInterface $paymentMethod, array $transactions): AdminOrderInterface
    {
        $qliroOrder = $this->createMock(AdminOrderInterface::class);
        $qliroOrder->method('getPaymentMethod')->willReturn($paymentMethod);
        $qliroOrder->method('getPaymentTransactions')->willReturn($transactions);

        return $qliroOrder;
    }

    private function transaction(string $type, ?string $paymentMethodName): AdminOrderPaymentTransactionInterface&MockObject
    {
        $transaction = $this->createMock(AdminOrderPaymentTransactionInterface::class);
        $transaction->method('getType')->willReturn($type);
        $transaction->method('getPaymentMethodName')->willReturn($paymentMethodName);

        return $transaction;
    }
}
