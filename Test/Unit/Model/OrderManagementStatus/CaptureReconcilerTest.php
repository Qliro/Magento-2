<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\OrderManagementStatus;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\AdminOrderInterface;
use Qliro\QliroOne\Api\Data\AdminOrderPaymentTransactionInterface;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusInterfaceFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Link;
use Qliro\QliroOne\Model\Api\Client\OrderManagement;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Management\TransactionStatus;
use Qliro\QliroOne\Model\Notification\QliroOrderManagementStatus;
use Qliro\QliroOne\Model\OrderManagementStatus;
use Qliro\QliroOne\Model\OrderManagementStatus\CaptureReconciler;

/**
 * @see \Qliro\QliroOne\Model\OrderManagementStatus\CaptureReconciler
 */
class CaptureReconcilerTest extends TestCase
{
    private OrderManagement&MockObject $api;
    private TransactionStatus&MockObject $transactionStatus;

    protected function setUp(): void
    {
        $this->api = $this->createMock(OrderManagement::class);
        $this->transactionStatus = $this->createMock(TransactionStatus::class);
    }

    /**
     * The capture is read from Qliro in the background profile, in the order's store
     */
    public function testFindsTheCaptureQliroSettled(): void
    {
        $this->api->expects(self::once())->method('getOrder')
            ->with(281424605, 3, Config::API_PROFILE_BACKGROUND)
            ->willReturn($this->qliroOrder([
                $this->transaction(329593507, 'Preauthorization', 'Success'),
                $this->transaction(329624897, 'Capture', 'Success'),
            ]));

        $found = $this->buildReconciler()->findSettled($this->capture());

        self::assertSame(329624897, $found->getPaymentTransactionId());
    }

    /**
     * A capture on invoice is requested inside the REST API's transaction too, and its record is the
     * payment rather than a shipment, so the order is found through the link for both
     */
    public function testFindsAnInvoiceCaptureThroughTheLink(): void
    {
        $this->api->expects(self::once())->method('getOrder')
            ->with(281424605, 3, Config::API_PROFILE_BACKGROUND)
            ->willReturn($this->qliroOrder([$this->transaction(329624897, 'Capture', 'Success')]));

        $capture = $this->capture();
        $capture->setData('record_type', 'payment');
        $capture->setData('record_id', 5);

        self::assertSame(329624897, $this->buildReconciler()->findSettled($capture)->getPaymentTransactionId());
    }

    /**
     * A capture still in process is left for a later run, and one Qliro does not list is not guessed at
     *
     * @dataProvider unsettledProvider
     */
    public function testLeavesACaptureQliroHasNotSettled(array $transactions): void
    {
        $this->api->method('getOrder')->willReturn($this->qliroOrder($transactions));

        self::assertNull($this->buildReconciler()->findSettled($this->capture()));
    }

    public static function unsettledProvider(): array
    {
        return [
            'in process' => [[['id' => 329624897, 'type' => 'Capture', 'status' => 'InProcess']]],
            'not listed' => [[['id' => 1, 'type' => 'Capture', 'status' => 'Success']]],
            'no transactions' => [[]],
        ];
    }

    /**
     * The status goes through the callback's own handling with the amount Qliro captured, which
     * the refunds later split by
     */
    public function testAppliesTheStatusThroughTheCallbackHandling(): void
    {
        $this->transactionStatus->expects(self::once())->method('handle')
            ->with(self::callback(fn (QliroOrderManagementStatus $notification) =>
                $notification->getOrderId() === 281424605
                && $notification->getPaymentTransactionId() === 329624897
                && $notification->getStatus() === 'Success'
                && $notification->getAmount() === 793.0
                && $notification->getCurrency() === 'SEK'
                && $notification->getMerchantReference() === '000246718'));
        $this->transactionStatus->method('isHandled')->with(329624897, 'Success')->willReturn(true);

        $applied = $this->buildReconciler()->apply(
            $this->capture(),
            $this->transaction(329624897, 'Capture', 'Success')
        );

        self::assertTrue($applied);
    }

    /**
     * A handler that failed leaves the transaction on its earlier status, which is reported
     */
    public function testReportsAStatusTheHandlerDidNotApply(): void
    {
        $this->transactionStatus->method('isHandled')->willReturn(false);

        self::assertFalse($this->buildReconciler()->apply(
            $this->capture(),
            $this->transaction(329624897, 'Capture', 'Success')
        ));
    }

    /**
     * @param array $transactions
     * @return AdminOrderInterface
     */
    private function qliroOrder(array $transactions): AdminOrderInterface
    {
        $order = $this->createMock(AdminOrderInterface::class);
        $order->method('getPaymentTransactions')->willReturn(array_map(
            fn ($t) => is_array($t) ? $this->transaction($t['id'], $t['type'], $t['status']) : $t,
            $transactions
        ));

        return $order;
    }

    /**
     * @param int $id
     * @param string $type
     * @param string $status
     * @return AdminOrderPaymentTransactionInterface
     */
    private function transaction(int $id, string $type, string $status): AdminOrderPaymentTransactionInterface
    {
        $transaction = $this->createMock(AdminOrderPaymentTransactionInterface::class);
        $transaction->method('getPaymentTransactionId')->willReturn($id);
        $transaction->method('getType')->willReturn($type);
        $transaction->method('getStatus')->willReturn($status);
        $transaction->method('getAmount')->willReturn(793.0);
        $transaction->method('getCurrency')->willReturn('SEK');

        return $transaction;
    }

    /**
     * @return OrderManagementStatus
     */
    private function capture(): OrderManagementStatus
    {
        $row = $this->row('Created');
        $row->addData(['record_id' => 12, 'transaction_id' => 329624897, 'qliro_order_id' => 281424605]);

        return $row;
    }

    /**
     * @param string $status
     * @return OrderManagementStatus
     */
    private function row(string $status): OrderManagementStatus
    {
        $row = $this->getMockBuilder(OrderManagementStatus::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $row->setData(['record_type' => 'shipment', 'transaction_status' => $status]);

        return $row;
    }

    /**
     * @return CaptureReconciler
     */
    private function buildReconciler(): CaptureReconciler
    {
        $order = $this->createMock(Order::class);
        $order->method('getIncrementId')->willReturn('000246718');
        $order->method('getStoreId')->willReturn(3);
        $orders = $this->createMock(OrderRepositoryInterface::class);
        $orders->method('get')->with(77)->willReturn($order);

        $link = $this->createMock(Link::class);
        $link->method('getOrderId')->willReturn(77);
        $links = $this->createMock(LinkRepositoryInterface::class);
        $links->method('getByQliroOrderId')->with(281424605, false)->willReturn($link);

        $factory = $this->createMock(QliroOrderManagementStatusInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(fn () => new QliroOrderManagementStatus());

        return new CaptureReconciler(
            $links,
            $orders,
            $this->api,
            $factory,
            $this->transactionStatus
        );
    }
}
