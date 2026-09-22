<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Admin\Builder;

use Magento\Framework\DataObject\IdentityService;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\AdminMarkItemsAsShippedRequestInterfaceFactory;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Api\RequestId;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\InvoiceMarkItemsAsShippedRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\InvoiceShipmentsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\MarkItemsAsShippedRequest;

/**
 * PLIN-363: a capture can now time out, and a capture that timed out after Qliro booked it leaves
 * no invoice behind. The merchant invoices again, and under a fresh RequestId that is the buyer's
 * money taken twice, so the same capture has to be sent under the same id.
 *
 * @see \Qliro\QliroOne\Model\QliroOrder\Admin\Builder\InvoiceMarkItemsAsShippedRequestBuilder
 */
class InvoiceMarkItemsAsShippedRequestBuilderTest extends TestCase
{
    /**
     * The same capture, asked for twice, is one request to Qliro.
     */
    public function testTheSameCaptureCarriesTheSameRequestId(): void
    {
        $first = $this->build(125.0, 0.0);
        $second = $this->build(125.0, 0.0);

        self::assertNotSame('', (string)$first->getRequestId());
        self::assertSame($first->getRequestId(), $second->getRequestId());
    }

    /**
     * A second capture on the same order is a capture of its own: what the order had already paid
     * has moved, so Qliro books it rather than recognising the first one.
     */
    public function testACaptureOnTopOfAnotherCarriesItsOwnRequestId(): void
    {
        self::assertNotSame(
            $this->build(125.0, 0.0)->getRequestId(),
            $this->build(125.0, 125.0)->getRequestId()
        );
    }

    /**
     * A capture of another amount is another request.
     */
    public function testAnotherAmountCarriesAnotherRequestId(): void
    {
        self::assertNotSame(
            $this->build(125.0, 0.0)->getRequestId(),
            $this->build(75.0, 0.0)->getRequestId()
        );
    }

    private function build(float $amount, float $alreadyPaid): MarkItemsAsShippedRequest
    {
        $builder = $this->builder();
        $builder->setPayment($this->payment($alreadyPaid));
        $builder->setAmount($amount);

        return $builder->create();
    }

    private function builder(): InvoiceMarkItemsAsShippedRequestBuilder
    {
        $requestFactory = $this->createMock(AdminMarkItemsAsShippedRequestInterfaceFactory::class);
        $requestFactory->method('create')
            ->willReturnCallback(static fn(): MarkItemsAsShippedRequest => new MarkItemsAsShippedRequest());

        $link = $this->createMock(LinkInterface::class);
        $link->method('getQliroOrderId')->willReturn(998877);
        $linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $linkRepository->method('getByOrderId')->willReturn($link);

        $shipmentsBuilder = $this->createMock(InvoiceShipmentsBuilder::class);
        $shipmentsBuilder->method('create')->willReturn([]);

        $config = $this->createMock(Config::class);
        $config->method('getMerchantApiKey')->willReturn('merchant-key');

        return new InvoiceMarkItemsAsShippedRequestBuilder(
            $requestFactory,
            $linkRepository,
            $this->createMock(LogManager::class),
            $shipmentsBuilder,
            $config,
            new RequestId(new IdentityService())
        );
    }

    private function payment(float $alreadyPaid): Payment&MockObject
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(5);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getOrderCurrencyCode')->willReturn('SEK');
        $order->method('getIncrementId')->willReturn('000000123');
        $order->method('getTotalPaid')->willReturn($alreadyPaid);

        $payment = $this->createMock(Payment::class);
        $payment->method('getOrder')->willReturn($order);

        return $payment;
    }
}
