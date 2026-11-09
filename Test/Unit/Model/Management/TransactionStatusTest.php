<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Management;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchResultsInterface;
use Magento\Framework\Exception\NoSuchEntityException;
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

    /** What the link lookup gives: a link with this order id, or the exception it throws */
    private int|null|\Exception $linkOrderId = 7;

    /** Whether the active lookup finds the link, otherwise only the list of all links does */
    private bool $linkActive = true;

    /** Lists a deactivated link without an order before the one with it */
    private bool $inactiveWithoutOrderFirst = false;

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
     * Qliro resends until the body says received, so a push without a link is acknowledged
     */
    public function testPushWithoutALinkIsReceivedAndSaysNothingWasUpdated(): void
    {
        $this->linkOrderId = new NoSuchEntityException();
        $this->statusRepository->expects(self::never())->method('save');
        $this->logManager->expects(self::never())->method('critical');
        $this->logManager->expects(self::never())->method('setMerchantReference');
        $this->logManager->expects(self::once())->method('log')->with(
            'info',
            self::anything(),
            self::callback(fn (array $context) => $context['message'] === QliroOrderManagementStatusResponseInterface::MESSAGE_NO_LINK
                && $context['qliro_order_id'] === 281424605
                && $context['status'] === 'Success'
                && $context['extra'] === ['qliro_order_id' => 281424605, 'merchant_reference' => '100000042'])
        );

        $response = $this->buildManagement()->handle($this->notification('Success', 'Reversal'));

        self::assertSame(QliroOrderManagementStatusResponseInterface::RESPONSE_RECEIVED, $response->getCallbackResponse());
        self::assertSame(QliroOrderManagementStatusResponseInterface::MESSAGE_NO_LINK, $response->getMessage());
    }

    /**
     * A checkout that never became an order has a link without one, and nothing to update
     */
    public function testPushForALinkWithoutAnOrderIsReceivedAndSaysNothingWasUpdated(): void
    {
        $this->linkOrderId = null;
        $this->statusRepository->expects(self::never())->method('save');
        $this->handlerPool->expects(self::never())->method('handle');
        $this->logManager->expects(self::once())->method('log')->with(
            'info',
            self::anything(),
            self::callback(fn (array $context) => $context['message'] === QliroOrderManagementStatusResponseInterface::MESSAGE_NO_ORDER
                && $context['extra']['merchant_reference'] === '100000042')
        );

        $response = $this->buildManagement()->handle($this->notification('Success', 'Reversal'));

        self::assertSame(QliroOrderManagementStatusResponseInterface::RESPONSE_RECEIVED, $response->getCallbackResponse());
        self::assertSame(QliroOrderManagementStatusResponseInterface::MESSAGE_NO_ORDER, $response->getMessage());
    }

    /**
     * Money taken on a link without an order may be for an order still being placed, so Qliro resends
     *
     * @dataProvider moneyTakingTypes
     */
    public function testMoneyTakenOnALinkWithoutAnOrderIsNotReceived(string $paymentType): void
    {
        $this->linkOrderId = null;
        $this->statusRepository->expects(self::never())->method('save');
        $this->logManager->expects(self::once())->method('log')->with(
            'warning',
            self::anything(),
            self::callback(fn (array $context) => $context['message'] === QliroOrderManagementStatusResponseInterface::RESPONSE_NOT_PLACED)
        );

        $response = $this->buildManagement()->handle($this->notification('Success', $paymentType));

        self::assertSame(QliroOrderManagementStatusResponseInterface::RESPONSE_NOT_PLACED, $response->getCallbackResponse());
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function moneyTakingTypes(): array
    {
        return ['capture' => ['Capture'], 'debit' => ['Debit']];
    }

    /**
     * A deactivated link will never get an order, so money taken on it is received
     */
    public function testMoneyTakenOnADeactivatedLinkWithoutAnOrderIsReceived(): void
    {
        $this->linkOrderId = null;
        $this->linkActive = false;
        $this->logManager->expects(self::once())->method('log')->with('warning');

        $response = $this->buildManagement()->handle($this->notification('Success', 'Debit'));

        self::assertSame(QliroOrderManagementStatusResponseInterface::RESPONSE_RECEIVED, $response->getCallbackResponse());
        self::assertSame(QliroOrderManagementStatusResponseInterface::MESSAGE_NO_ORDER, $response->getMessage());
    }

    /**
     * Money taken on a Qliro order the store has no link for can never be applied, but is worth a warning
     */
    public function testMoneyTakenWithoutALinkIsReceivedWithAWarning(): void
    {
        $this->linkOrderId = new NoSuchEntityException();
        $this->logManager->expects(self::once())->method('log')->with('warning');

        $response = $this->buildManagement()->handle($this->notification('Success', 'Capture'));

        self::assertSame(QliroOrderManagementStatusResponseInterface::RESPONSE_RECEIVED, $response->getCallbackResponse());
        self::assertSame(QliroOrderManagementStatusResponseInterface::MESSAGE_NO_LINK, $response->getMessage());
    }

    /**
     * A value MySQL would cast to another id never reaches the lookup of deactivated links
     */
    public function testNonNumericOrderIdIsNotListed(): void
    {
        $this->linkActive = false;
        $this->logManager->expects(self::once())->method('log')->with('info');

        $notification = $this->notification('Success', 'Reversal')->setOrderId('281424605abc');
        $management = $this->buildManagement('281424605abc');

        $response = $management->handle($notification);

        self::assertSame(QliroOrderManagementStatusResponseInterface::MESSAGE_NO_LINK, $response->getMessage());
    }

    /**
     * Only money taken without an order is a warning, an abandoned checkout's statuses are routine
     */
    public function testCancelledPreauthorizationWithoutAnOrderIsLoggedAtInfo(): void
    {
        $this->linkOrderId = null;
        $this->logManager->expects(self::once())->method('log')->with('info');

        $this->buildManagement()->handle($this->notification('Cancelled', 'Preauthorization'));
    }

    /**
     * Of the deactivated links of a Qliro order, the one with the order is used
     */
    public function testDeactivatedLinkWithTheOrderIsPreferred(): void
    {
        $this->linkActive = false;
        $this->inactiveWithoutOrderFirst = true;
        $this->statusRepository->method('getParent')->willReturn($this->row('cancel', 'Created'));
        $this->handlerPool->expects(self::once())->method('handle')->willReturn(true);
        $this->logManager->expects(self::never())->method('log');

        $response = $this->buildManagement()->handle($this->notification('Success', 'Reversal'));

        self::assertSame(QliroOrderManagementStatusResponseInterface::RESPONSE_RECEIVED, $response->getCallbackResponse());
    }

    /**
     * A lookup that fails is not a missing link, Qliro has to send it again
     */
    public function testFailedLinkLookupIsNotReceived(): void
    {
        $this->linkOrderId = new \RuntimeException('MySQL server has gone away');
        $this->logManager->expects(self::once())->method('critical');
        $this->logManager->expects(self::never())->method('log');

        $response = $this->buildManagement()->handle($this->notification('Success'));

        self::assertSame(QliroOrderManagementStatusResponseInterface::RESPONSE_FAILED, $response->getCallbackResponse());
        self::assertNull($response->getMessage());
    }

    /**
     * A status that could not be stored is not received either
     */
    public function testStatusThatCouldNotBeStoredIsNotReceived(): void
    {
        $this->statusRepository->method('getParent')->willReturn($this->row('shipment', 'Created'));
        $this->statusRepository->method('save')->willThrowException(new \RuntimeException('Deadlock found'));
        $this->handlerPool->expects(self::never())->method('handle');
        $this->logManager->expects(self::once())->method('critical');

        $response = $this->buildManagement()->handle($this->notification('Success'));

        self::assertSame(QliroOrderManagementStatusResponseInterface::RESPONSE_FAILED, $response->getCallbackResponse());
    }

    /**
     * A cancelled order's link is deactivated and still names the order the status belongs to
     */
    public function testDeactivatedLinkWithAnOrderIsHandled(): void
    {
        $this->linkActive = false;
        $this->statusRepository->method('getParent')->willReturn($this->row('cancel', 'Created'));
        $this->handlerPool->expects(self::once())->method('handle')->willReturn(true);

        $response = $this->buildManagement()->handle($this->notification('Success', 'Reversal'));

        self::assertSame(QliroOrderManagementStatusResponseInterface::RESPONSE_RECEIVED, $response->getCallbackResponse());
        self::assertNull($response->getMessage());
    }

    /**
     * A handled status is received with nothing else in the body
     */
    public function testHandledStatusIsReceivedWithoutAMessage(): void
    {
        $this->statusRepository->method('getParent')->willReturn($this->row('shipment', 'Created'));
        $this->handlerPool->method('handle')->willReturn(true);

        $response = $this->buildManagement()->handle($this->notification('Success'));

        self::assertSame(QliroOrderManagementStatusResponseInterface::RESPONSE_RECEIVED, $response->getCallbackResponse());
        self::assertNull($response->getMessage());
    }

    /**
     * @param string $status
     * @param string $paymentType
     * @return QliroOrderManagementStatus
     */
    private function notification(string $status, string $paymentType = 'Capture'): QliroOrderManagementStatus
    {
        return (new QliroOrderManagementStatus())
            ->setOrderId(281424605)
            ->setPaymentType($paymentType)
            ->setMerchantReference('100000042')
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
     * @param int|string $qliroOrderId
     * @return TransactionStatus
     */
    private function buildManagement(int|string $qliroOrderId = 281424605): TransactionStatus
    {
        $linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $orderId = $this->linkOrderId instanceof \Exception ? null : $this->linkOrderId;
        $link = $this->link($orderId);
        $expectedId = $qliroOrderId;
        $linkRepository->method('getByQliroOrderId')->willReturnCallback(
            function ($qliroOrderId) use ($link, $expectedId) {
                self::assertSame($expectedId, $qliroOrderId);
                if ($this->linkOrderId instanceof \Exception) {
                    throw $this->linkOrderId;
                }
                if (!$this->linkActive) {
                    throw new NoSuchEntityException();
                }

                return $link;
            }
        );
        $found = $this->linkOrderId instanceof NoSuchEntityException ? [] : [$link];
        if ($this->inactiveWithoutOrderFirst) {
            array_unshift($found, $this->link(null));
        }
        $results = $this->createMock(SearchResultsInterface::class);
        $results->method('getItems')->willReturn($found);
        if (is_string($qliroOrderId)) {
            $linkRepository->expects(self::never())->method('getList');
        }
        $linkRepository->method('getList')->willReturn($results);

        $searchCriteriaBuilder = $this->createMock(SearchCriteriaBuilder::class);
        $searchCriteriaBuilder->method('addFilter')->with('qliro_order_id', 281424605)->willReturnSelf();
        $searchCriteriaBuilder->method('create')->willReturn(new SearchCriteria());

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
            $this->handlerPool,
            $searchCriteriaBuilder
        );
    }

    /**
     * @param int|null $orderId
     * @return LinkInterface
     */
    private function link(?int $orderId): LinkInterface
    {
        $link = $this->createMock(LinkInterface::class);
        $link->method('getOrderId')->willReturn($orderId);
        $link->method('getReference')->willReturn('ref');
        $link->method('getIsActive')->willReturn($this->linkActive ? 1 : 0);

        return $link;
    }
}
