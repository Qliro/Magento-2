<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Management;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterfaceFactory;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusResponseInterface;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusResponseInterfaceFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\TransactionStatus;
use Qliro\QliroOne\Model\Notification\QliroOrderManagementStatus;
use Qliro\QliroOne\Model\Notification\QliroOrderManagementStatusResponse;
use Qliro\QliroOne\Model\OrderManagementStatus;
use Qliro\QliroOne\Model\OrderManagementStatus\Update\HandlerPool;
use Qliro\QliroOne\Model\ResourceModel\Lock;

/**
 * @see \Qliro\QliroOne\Model\Management\TransactionStatus
 */
class TransactionStatusTest extends TestCase
{
    private OrderManagementStatusRepositoryInterface&MockObject $statusRepository;
    private HandlerPool&MockObject $handlerPool;
    private LogManager&MockObject $logManager;
    private Lock&MockObject $lock;

    /** The last handled status each lookup finds, in order, null for none */
    private array $lastHandled = [];

    protected function setUp(): void
    {
        $this->statusRepository = $this->createMock(OrderManagementStatusRepositoryInterface::class);
        $this->handlerPool = $this->createMock(HandlerPool::class);
        $this->logManager = $this->createMock(LogManager::class);
        $this->lock = $this->createMock(Lock::class);
        $this->lock->method('lock')->willReturn(true);
        $this->statusRepository->method('getPrevious')->willReturnCallback(function () {
            $status = array_shift($this->lastHandled);

            return $status === null ? null : $this->row('shipment', $status);
        });
    }

    /**
     * A capture confirmed before its shipment is committed has no record yet. It is kept as an
     * unhandled row for the reconciliation, and Qliro is still told it was received.
     */
    public function testCallbackWithoutARecordIsKeptForTheReconciliation(): void
    {
        $this->statusRepository->method('getParent')->willReturn(null);
        $this->handlerPool->method('handle')->willReturn(false);
        $this->logManager->expects(self::once())->method('info')
            ->with(self::stringContains('the capture reconciliation applies it'));

        $saved = [];
        $this->statusRepository->method('save')->willReturnCallback(
            function (OrderManagementStatus $row) use (&$saved) {
                $saved[] = $row->getNotificationStatus();
                return $row;
            }
        );

        $response = $this->buildManagement()->handle($this->notification('Success'));

        self::assertSame(QliroOrderManagementStatusResponseInterface::RESPONSE_RECEIVED, $response->getCallbackResponse());
        self::assertSame(
            [OrderManagementStatusInterface::NOTIFICATION_STATUS_NEW, OrderManagementStatusInterface::NOTIFICATION_STATUS_NEW],
            $saved
        );
    }

    /**
     * The reconciliation and Qliro's callback can both pass the first check. The one that gets
     * the lock second finds the status handled and must not invoice the shipment again.
     */
    public function testStatusHandledWhileWaitingForTheLockIsSkipped(): void
    {
        $this->statusRepository->method('getParent')->willReturn($this->row('shipment', 'Created'));
        $this->lastHandled = ['Created', 'Success'];
        $this->handlerPool->expects(self::never())->method('handle');

        $saved = [];
        $this->statusRepository->method('save')->willReturnCallback(
            function (OrderManagementStatus $row) use (&$saved) {
                $saved[] = $row->getNotificationStatus();
                return $row;
            }
        );

        $this->buildManagement()->handle($this->notification('Success'));

        self::assertSame([OrderManagementStatusInterface::NOTIFICATION_STATUS_SKIPPED], $saved);
    }

    /**
     * A status that is new for the transaction reaches the handler of the record it belongs to.
     */
    public function testNewStatusIsHandled(): void
    {
        $this->statusRepository->method('getParent')->willReturn($this->row('shipment', 'Created'));
        $this->handlerPool->expects(self::once())->method('handle')
            ->with(
                self::anything(),
                self::callback(fn (OrderManagementStatus $row) => $row->getRecordType() === 'shipment'
                    && (int)$row->getRecordId() === 12)
            )
            ->willReturn(true);

        $statuses = [];
        $this->statusRepository->method('save')->willReturnCallback(
            function (OrderManagementStatus $row) use (&$statuses) {
                $statuses[] = $row->getNotificationStatus();
                return $row;
            }
        );

        $this->buildManagement()->handle($this->notification('Success'));

        self::assertSame(OrderManagementStatusInterface::NOTIFICATION_STATUS_DONE, end($statuses));
    }

    /**
     * Saved as handled before the lock goes, otherwise the next one in would not see it
     */
    public function testHandledRowIsSavedBeforeTheUnlock(): void
    {
        $this->statusRepository->method('getParent')->willReturn($this->row('shipment', 'Created'));
        $this->handlerPool->method('handle')->willReturn(true);

        $events = [];
        $this->statusRepository->method('save')->willReturnCallback(
            function (OrderManagementStatus $row) use (&$events) {
                $events[] = 'save ' . $row->getNotificationStatus();
                return $row;
            }
        );
        $this->lock->method('unlock')->willReturnCallback(function () use (&$events) {
            $events[] = 'unlock';
            return 1;
        });

        $this->buildManagement()->handle($this->notification('Success'));

        self::assertSame(['save new', 'save handled', 'unlock'], $events);
    }

    /**
     * A shipment committed while the callback waited for the lock is found under it
     */
    public function testRecordCommittedWhileWaitingForTheLockIsHandled(): void
    {
        $this->statusRepository->method('getParent')->willReturnOnConsecutiveCalls(null, $this->row('shipment', 'Created'));
        $this->handlerPool->expects(self::once())->method('handle')
            ->with(self::anything(), self::callback(fn (OrderManagementStatus $row) => $row->getRecordType() === 'shipment'))
            ->willReturn(true);

        $this->buildManagement()->handle($this->notification('Success'));
    }

    /**
     * A lock held by another handling is waited for rather than the status dropped
     */
    public function testWaitsForALockHeldByAnotherHandling(): void
    {
        $this->lock = $this->createMock(Lock::class);
        $this->lock->method('lock')->willReturnOnConsecutiveCalls(false, true);
        $this->statusRepository->method('getParent')->willReturn($this->row('shipment', 'Created'));
        $this->handlerPool->expects(self::once())->method('handle')->willReturn(true);

        $this->buildManagement()->handle($this->notification('Success'));
    }

    /**
     * @param string $status
     * @return QliroOrderManagementStatus
     */
    private function notification(string $status): QliroOrderManagementStatus
    {
        return (new QliroOrderManagementStatus())
            ->setOrderId(281424605)
            ->setPaymentTransactionId(329624897)
            ->setStatus($status);
    }

    /**
     * @param string $type
     * @param string $status
     * @return OrderManagementStatus
     */
    private function row(string $type, string $status): OrderManagementStatus
    {
        $row = $this->getMockBuilder(OrderManagementStatus::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $row->setData([
            'record_type' => $type,
            'record_id' => 12,
            'transaction_status' => $status,
        ]);

        return $row;
    }

    /**
     * @return TransactionStatus
     */
    private function buildManagement(): TransactionStatus
    {
        $link = $this->createMock(LinkInterface::class);
        $link->method('getOrderId')->willReturn(7);
        $link->method('getReference')->willReturn('ref');
        $linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $linkRepository->method('getByQliroOrderId')->willReturn($link);

        $responseFactory = $this->createMock(QliroOrderManagementStatusResponseInterfaceFactory::class);
        $responseFactory->method('create')->willReturnCallback(fn () => new QliroOrderManagementStatusResponse());

        $statusFactory = $this->createMock(OrderManagementStatusInterfaceFactory::class);
        $statusFactory->method('create')->willReturnCallback(
            fn () => $this->getMockBuilder(OrderManagementStatus::class)
                ->disableOriginalConstructor()
                ->onlyMethods([])
                ->getMock()
        );

        return new TransactionStatus(
            $linkRepository,
            $this->logManager,
            $this->lock,
            $responseFactory,
            $statusFactory,
            $this->statusRepository,
            $this->handlerPool
        );
    }
}
