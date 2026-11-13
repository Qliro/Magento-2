<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Management;

use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderPaymentRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterfaceFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Link;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\Payment as PaymentManagement;
use Qliro\QliroOne\Model\Order\StateStatus;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\AddItemsToInvoiceBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\InvoiceMarkItemsAsShippedRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\ShipmentMarkItemsAsShippedRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\CaptureCoverage;
use Qliro\QliroOne\Model\QliroOrder\Admin\CaptureRefundAllocator;
use Qliro\QliroOne\Model\QliroOrder\Admin\RepeatedCapture;
use Qliro\QliroOne\Model\QliroOrder\Admin\SequentialRefundProcessor;
use Qliro\QliroOne\Model\QliroOrder\ReservationFormat;

/**
 * @see \Qliro\QliroOne\Model\Management\Payment::addItemsToInvoice()
 */
class PaymentRefundTest extends TestCase
{
    public function testQueuesEachCaptureShareWithItsOwnVatSplit(): void
    {
        $creditMemo = $this->createMock(Creditmemo::class);
        $creditMemo->method('getGrandTotal')->willReturn(300.0);

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(5);

        $payment = $this->createMock(Payment::class);
        $payment->method('getOrder')->willReturn($order);
        $payment->method('getCreditmemo')->willReturn($creditMemo);

        $allocator = $this->createMock(CaptureRefundAllocator::class);
        $allocator->method('allocate')->with($payment, 300.0)->willReturn([
            3 => ['payment_transaction_id' => 11, 'amount' => 200.0],
            7 => ['payment_transaction_id' => 12, 'amount' => 100.0],
        ]);

        $first = [['vat_rate' => 25.0, 'amount' => 120.0], ['vat_rate' => 12.0, 'amount' => 80.0]];
        $second = [['vat_rate' => 25.0, 'amount' => 60.0], ['vat_rate' => 12.0, 'amount' => 40.0]];

        $builder = $this->createMock(AddItemsToInvoiceBuilder::class);
        $builder->method('getRefundVatSplits')->with($payment, [200.0, 100.0])->willReturn([$first, $second]);

        $processor = $this->createMock(SequentialRefundProcessor::class);
        $processor->expects(self::once())->method('start')->with($payment, [
            ['payment_transaction_id' => 11, 'amount' => 200.0, 'vat_split' => $first],
            ['payment_transaction_id' => 12, 'amount' => 100.0, 'vat_split' => $second],
        ]);

        $this->management($allocator, $builder, $processor)->addItemsToInvoice($payment);
    }

    private function management(
        CaptureRefundAllocator $allocator,
        AddItemsToInvoiceBuilder $builder,
        SequentialRefundProcessor $processor
    ): PaymentManagement {
        $link = $this->createMock(Link::class);
        $link->method('getReference')->willReturn('ZW1YpE');

        $linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $linkRepository->method('getByOrderId')->willReturn($link);

        return new PaymentManagement(
            $this->createMock(Config::class),
            $this->createMock(OrderManagementInterface::class),
            $linkRepository,
            $this->createMock(OrderRepositoryInterface::class),
            $this->createMock(LogManager::class),
            $this->createMock(BuilderInterface::class),
            $this->createMock(OrderManagementStatusInterfaceFactory::class),
            $this->createMock(OrderManagementStatusRepositoryInterface::class),
            $this->createMock(InvoiceMarkItemsAsShippedRequestBuilder::class),
            $this->createMock(ShipmentMarkItemsAsShippedRequestBuilder::class),
            $builder,
            $allocator,
            $processor,
            $this->createMock(ReservationFormat::class),
            $this->createMock(CaptureCoverage::class),
            $this->createMock(OrderPaymentRepositoryInterface::class),
            $this->createMock(RepeatedCapture::class),
            $this->createMock(LockManagerInterface::class),
            $this->createMock(StateStatus::class)
        );
    }
}
