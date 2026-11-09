<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder;

use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface;
use Qliro\QliroOne\Api\Data\AdminUpdateMerchantReferenceRequestInterface;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\MerchantReference;

/**
 * @see MerchantReference
 */
class MerchantReferenceTest extends TestCase
{
    private Config&MockObject $config;
    private ContainerMapper&MockObject $containerMapper;
    private OrderManagementInterface&MockObject $api;
    private OrderPaymentRepositoryInterface&MockObject $paymentRepository;
    private LogManager&MockObject $logManager;
    private array $info = [];
    private array $calls = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->containerMapper = $this->createMock(ContainerMapper::class);
        $this->api = $this->createMock(OrderManagementInterface::class);
        $this->paymentRepository = $this->createMock(OrderPaymentRepositoryInterface::class);
        $this->logManager = $this->createMock(LogManager::class);

        $this->containerMapper->method('fromArray')->willReturnCallback(function (array $data) {
            $this->calls[] = 'request ' . $data['OrderId'] . ' ' . $data['NewMerchantReference'];

            return $this->createMock(AdminUpdateMerchantReferenceRequestInterface::class);
        });
        $this->paymentRepository->method('save')->willReturnCallback(function ($payment) {
            $this->calls[] = 'save ' . json_encode($this->info);

            return $payment;
        });
    }

    public function testTheMarkIsSavedBeforeTheCall(): void
    {
        $this->api->expects(self::once())->method('updateMerchantReference')
            ->willReturnCallback(function () {
                $this->calls[] = 'call';

                return $this->response('3461592');
            });
        $this->logManager->expects(self::never())->method('critical');

        $this->merchantReference()->sendOnce($this->order(), $this->link());

        self::assertSame(
            ['save {"qliroone_updated_merchant_reference":true}', 'request 5601709 493792664', 'call'],
            $this->calls
        );
    }

    public function testAnOrderMarkedBeforeIsNotSentAgain(): void
    {
        $this->info = [MerchantReference::KEY_SENT => true];
        $this->api->expects(self::never())->method('updateMerchantReference');
        $this->paymentRepository->expects(self::never())->method('save');

        $this->merchantReference()->sendOnce($this->order(), $this->link());
    }

    public function testASecondCallbackOnTheSameOrderSendsNothing(): void
    {
        $this->api->expects(self::once())->method('updateMerchantReference')->willReturn($this->response('1'));
        $order = $this->order();

        $this->merchantReference()->sendOnce($order, $this->link());
        $this->merchantReference()->sendOnce($order, $this->link());
    }

    /**
     * Fire once whatever Qliro answered, the failure is logged
     */
    public function testAFailedCallIsLoggedAndNotRepeated(): void
    {
        $this->api->expects(self::once())->method('updateMerchantReference')->willReturn(null);
        $this->logManager->expects(self::once())->method('critical');
        $order = $this->order();

        $this->merchantReference()->sendOnce($order, $this->link());
        $this->merchantReference()->sendOnce($order, $this->link());

        self::assertTrue($this->info[MerchantReference::KEY_SENT]);
    }

    /**
     * Qliro answered that the order already carries the number, the client hands back an empty response
     */
    public function testAnAlreadySetReferenceIsNotLoggedAsAFailure(): void
    {
        $this->api->method('updateMerchantReference')->willReturn($this->response(null));
        $this->logManager->expects(self::never())->method('critical');
        $this->logManager->expects(self::once())->method('debug')
            ->with(self::anything(), self::callback(
                static fn (array $context): bool => $context['payment_transaction_id'] === 'unknown'
            ));

        $this->merchantReference()->sendOnce($this->order(), $this->link());
    }

    /**
     * A failed save of the mark does not stop the call or the status update after it
     */
    public function testAMarkThatCannotBeSavedStillSends(): void
    {
        $this->paymentRepository = $this->createMock(OrderPaymentRepositoryInterface::class);
        $this->paymentRepository->method('save')->willThrowException(new \RuntimeException('Lock wait timeout'));
        $this->api->expects(self::once())->method('updateMerchantReference')->willReturn($this->response('1'));
        $this->logManager->expects(self::once())->method('critical');

        $this->merchantReference()->sendOnce($this->order(), $this->link());

        self::assertTrue($this->info[MerchantReference::KEY_SENT]);
    }

    /**
     * The order save that follows keeps the mark, no separate write
     */
    public function testAStoreOnIncrementIdsIsMarkedWithoutACall(): void
    {
        $this->config->method('isUseIncrementIdAsReference')->with(1)->willReturn(true);
        $this->api->expects(self::never())->method('updateMerchantReference');
        $this->paymentRepository->expects(self::never())->method('save');

        $this->merchantReference()->sendOnce($this->order(), $this->link());

        self::assertTrue($this->info[MerchantReference::KEY_SENT]);
    }

    private function merchantReference(): MerchantReference
    {
        return new MerchantReference(
            $this->config,
            $this->containerMapper,
            $this->api,
            $this->paymentRepository,
            $this->logManager
        );
    }

    private function order(): Order&MockObject
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturnCallback(
            fn ($key = null) => $key === null ? $this->info : ($this->info[$key] ?? null)
        );
        $payment->method('setAdditionalInformation')->willReturnCallback(
            function ($key, $value = null) use ($payment) {
                $this->info[$key] = $value;

                return $payment;
            }
        );

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('getId')->willReturn(4);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getIncrementId')->willReturn('493792664');

        return $order;
    }

    private function link(): LinkInterface&MockObject
    {
        $link = $this->createMock(LinkInterface::class);
        $link->method('getQliroOrderId')->willReturn(5601709);

        return $link;
    }

    private function response(?string $transactionId): AdminTransactionResponseInterface&MockObject
    {
        $response = $this->createMock(AdminTransactionResponseInterface::class);
        $response->method('getPaymentTransactionId')->willReturn($transactionId);

        return $response;
    }
}
