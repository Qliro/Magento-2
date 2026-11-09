<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Management;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\Data\MerchantNotificationResponseInterfaceFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\MerchantNotification;
use Qliro\QliroOne\Model\Notification\MerchantNotification as Notification;
use Qliro\QliroOne\Model\Notification\MerchantNotificationResponse;
use Qliro\QliroOne\Model\Order\ShippingProviderUpdate;
use Qliro\QliroOne\Model\ResourceModel\Lock;

/**
 * @see \Qliro\QliroOne\Model\Management\MerchantNotification
 */
class MerchantNotificationTest extends TestCase
{
    private const QLIRO_ORDER_ID = 4400;
    private const LINK_ID = 9;
    private const PAYLOAD = ['service' => ['name' => 'PostNord MyPack', 'id' => 'P19']];

    private LinkRepositoryInterface&MockObject $linkRepository;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private ShippingProviderUpdate&MockObject $shippingUpdate;
    private Lock&MockObject $lock;

    /** Order ids the link reads, in order */
    private array $orderIds = [];

    protected function setUp(): void
    {
        $this->linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $this->linkRepository->method('getByQliroOrderId')->willReturnCallback(function () {
            $link = $this->createMock(LinkInterface::class);
            $link->method('getId')->willReturn(self::LINK_ID);
            $link->method('getOrderId')->willReturn(array_shift($this->orderIds));

            return $link;
        });
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->shippingUpdate = $this->createMock(ShippingProviderUpdate::class);
        $this->lock = $this->createMock(Lock::class);
    }

    public function testEarlyUpdateIsStoredUnderTheLockAndAccepted(): void
    {
        $this->orderIds = [null];
        $calls = [];
        $this->lock->method('lock')->willReturnCallback(static function () use (&$calls): bool {
            $calls[] = 'lock';

            return true;
        });
        $this->lock->expects(self::once())->method('unlock')->with(self::QLIRO_ORDER_ID)
            ->willReturnCallback(static function () use (&$calls): bool {
                $calls[] = 'unlock';

                return true;
            });
        $this->shippingUpdate->expects(self::once())->method('store')
            ->with(self::LINK_ID, 'Unifaun', self::PAYLOAD)
            ->willReturnCallback(static function () use (&$calls): bool {
                $calls[] = 'store';

                return true;
            });
        $this->orderRepository->expects(self::never())->method('get');

        $response = $this->management()->execute($this->notification());

        self::assertSame('received', $response->getCallbackResponse());
        self::assertSame(['lock', 'store', 'unlock'], $calls);
    }

    /** A placement holding the lock may have read the stored updates already, so Qliro tries again */
    public function testLockedQliroOrderIsRetriedByQliro(): void
    {
        $this->lock->method('lock')->willReturn(false);
        $this->lock->expects(self::never())->method('unlock');
        $this->shippingUpdate->expects(self::never())->method('store');
        $this->shippingUpdate->expects(self::never())->method('apply');

        $response = $this->management()->execute($this->notification());

        self::assertSame(409, $response->getCallbackResponseCode());
        self::assertNotSame('received', $response->getCallbackResponse());
    }

    public function testLockFreedShortlyIsWaitedFor(): void
    {
        $this->orderIds = [null];
        $this->lock->method('lock')->willReturnOnConsecutiveCalls(false, true);
        $this->shippingUpdate->expects(self::once())->method('store');

        self::assertSame(200, $this->management()->execute($this->notification())->getCallbackResponseCode());
    }

    public function testFailureToStoreIsRetriedByQliro(): void
    {
        $this->orderIds = [null];
        $this->lock->method('lock')->willReturn(true);
        $this->lock->expects(self::once())->method('unlock');
        $this->shippingUpdate->method('store')->willThrowException(new \RuntimeException('Deadlock found'));

        $response = $this->management()->execute($this->notification());

        self::assertSame(500, $response->getCallbackResponseCode());
    }

    public function testUpdateForAPlacedOrderIsSavedAndReplacesAStoredOne(): void
    {
        $this->orderIds = [31];
        $this->lock->method('lock')->willReturn(true);
        $order = $this->createMock(Order::class);
        $this->orderRepository->method('get')->with(31)->willReturn($order);
        $this->shippingUpdate->expects(self::once())->method('apply')
            ->with($order, 'Unifaun', self::PAYLOAD)->willReturn(true);
        $this->orderRepository->expects(self::once())->method('save')->with($order);
        $this->shippingUpdate->expects(self::never())->method('discard');
        $this->shippingUpdate->expects(self::never())->method('store');

        $response = $this->management()->execute($this->notification());

        self::assertSame(200, $response->getCallbackResponseCode());
        self::assertSame('received', $response->getCallbackResponse());
    }

    /** The order then compares the incoming update with the newer of the two */
    public function testStoredUpdateIsPutOnThePlacedOrderBeforeTheIncomingOne(): void
    {
        $this->orderIds = [31];
        $this->lock->method('lock')->willReturn(true);
        $order = $this->createMock(Order::class);
        $this->orderRepository->method('get')->willReturn($order);
        $calls = [];
        $this->shippingUpdate->method('applyStored')->with($order, self::LINK_ID)
            ->willReturnCallback(static function () use (&$calls): bool {
                $calls[] = 'stored';

                return true;
            });
        $this->shippingUpdate->method('apply')->willReturnCallback(static function () use (&$calls): bool {
            $calls[] = 'incoming';

            return false;
        });
        $this->shippingUpdate->method('discard')->willReturnCallback(static function () use (&$calls): void {
            $calls[] = 'discard';
        });
        $this->orderRepository->expects(self::once())->method('save')->with($order);

        self::assertSame('received', $this->management()->execute($this->notification())->getCallbackResponse());
        self::assertSame(['stored', 'incoming', 'discard'], $calls);
    }

    public function testUnreadableStoredUpdateDoesNotStopTheIncomingOne(): void
    {
        $this->orderIds = [31];
        $this->lock->method('lock')->willReturn(true);
        $order = $this->createMock(Order::class);
        $this->orderRepository->method('get')->willReturn($order);
        $this->shippingUpdate->method('applyStored')->willThrowException(new \InvalidArgumentException('unreadable'));
        $this->shippingUpdate->expects(self::once())->method('apply')->willReturn(true);
        $this->orderRepository->expects(self::once())->method('save')->with($order);

        self::assertSame('received', $this->management()->execute($this->notification())->getCallbackResponse());
    }

    /** Qliro's own field name, which the mapper matches by case */
    public function testQliroTimestampIsMappedFromThePayload(): void
    {
        $logManager = $this->createMock(LogManager::class);
        $objectManager = $this->createMock(\Magento\Framework\ObjectManagerInterface::class);
        $objectManager->method('create')->willReturnCallback(static fn () => new Notification());
        $mapper = new \Qliro\QliroOne\Model\ContainerMapper($objectManager, $logManager);

        $notification = $mapper->fromArray(
            ['OrderId' => 1, 'Timestamp' => '2026-11-04T10:00:00Z'],
            \Qliro\QliroOne\Api\Data\MerchantNotificationInterface::class
        );

        self::assertSame('2026-11-04T10:00:00Z', $notification->getTimestamp());
        self::assertSame('', (new Notification())->setTimestamp(null)->getTimestamp());
        self::assertSame('1793800000', (new Notification())->setTimestamp(1793800000)->getTimestamp());
    }

    public function testRepeatedUpdateForAPlacedOrderIsNotSavedAgain(): void
    {
        $this->orderIds = [31];
        $this->lock->method('lock')->willReturn(true);
        $this->orderRepository->method('get')->willReturn($this->createMock(Order::class));
        $this->shippingUpdate->method('apply')->willReturn(false);
        $this->orderRepository->expects(self::never())->method('save');

        $response = $this->management()->execute($this->notification());

        // Qliro sends anything but this answer again
        self::assertSame('received', $response->getCallbackResponse());
    }

    public function testFailedOrderSaveKeepsTheStoredUpdateAndIsRetried(): void
    {
        $this->orderIds = [31];
        $this->lock->method('lock')->willReturn(true);
        $this->lock->expects(self::once())->method('unlock');
        $this->orderRepository->method('get')->willReturn($this->createMock(Order::class));
        $this->shippingUpdate->method('apply')->willReturn(true);
        $this->orderRepository->method('save')->willThrowException(new \RuntimeException('Lock wait timeout'));
        $this->shippingUpdate->expects(self::never())->method('discard');

        self::assertSame(500, $this->management()->execute($this->notification())->getCallbackResponseCode());
    }

    /** Kept in case the link is taken up again, Qliro stops sending it either way */
    public function testUpdateForAnInactiveLinkIsStored(): void
    {
        $inactive = $this->createMock(LinkInterface::class);
        $inactive->method('getId')->willReturn(self::LINK_ID);
        $linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $linkRepository->method('getByQliroOrderId')->willReturnCallback(
            static function ($id, $onlyActive = true) use ($inactive) {
                if ($onlyActive) {
                    throw new NoSuchEntityException();
                }

                return $inactive;
            }
        );
        $this->linkRepository = $linkRepository;
        $this->lock->method('lock')->willReturn(true);
        $this->shippingUpdate->expects(self::once())->method('store')->with(self::LINK_ID);

        self::assertSame('received', $this->management()->execute($this->notification())->getCallbackResponse());
    }

    public function testUpdateForACanceledOrderIsAcknowledgedAndSkipped(): void
    {
        $this->orderIds = [31];
        $this->lock->method('lock')->willReturn(true);
        $order = $this->createMock(Order::class);
        $order->method('getState')->willReturn(Order::STATE_CANCELED);
        $this->orderRepository->method('get')->willReturn($order);
        $this->orderRepository->expects(self::never())->method('save');
        $this->shippingUpdate->expects(self::never())->method('apply');
        $this->shippingUpdate->expects(self::once())->method('discard')->with(self::LINK_ID);

        self::assertSame('received', $this->management()->execute($this->notification())->getCallbackResponse());
    }

    public function testLockTableFailureIsAnsweredForARetry(): void
    {
        $this->lock->method('lock')->willThrowException(new \RuntimeException('Deadlock found'));

        $response = $this->management()->execute($this->notification());

        self::assertSame(500, $response->getCallbackResponseCode());
        self::assertNotSame('received', $response->getCallbackResponse());
    }

    public function testQliroTimestampGoesWithTheUpdate(): void
    {
        $this->orderIds = [null];
        $this->lock->method('lock')->willReturn(true);
        $this->shippingUpdate->expects(self::once())->method('store')
            ->with(self::LINK_ID, 'Unifaun', self::PAYLOAD, '2026-11-04T10:00:00.5+01:00');

        $this->management()->execute($this->notification()->setTimestamp('2026-11-04T10:00:00.5+01:00'));
    }

    public function testFailedDropOfTheStoredUpdateStillAcknowledges(): void
    {
        $this->orderIds = [31];
        $this->lock->method('lock')->willReturn(true);
        $this->orderRepository->method('get')->willReturn($this->createMock(Order::class));
        $this->shippingUpdate->method('applyStored')->willReturn(true);
        $this->shippingUpdate->method('apply')->willReturn(true);
        $this->shippingUpdate->method('discard')->willThrowException(new \RuntimeException('Lock wait timeout'));

        self::assertSame('received', $this->management()->execute($this->notification())->getCallbackResponse());
    }

    public function testUnknownQliroOrderIsAnError(): void
    {
        $linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $linkRepository->method('getByQliroOrderId')->willThrowException(new NoSuchEntityException());
        $this->linkRepository = $linkRepository;
        $this->lock->method('lock')->willReturn(true);
        $this->lock->expects(self::once())->method('unlock');

        self::assertSame(500, $this->management()->execute($this->notification())->getCallbackResponseCode());
    }

    private function notification(): Notification
    {
        return (new Notification())
            ->setOrderId(self::QLIRO_ORDER_ID)
            ->setMerchantReference('000000031')
            ->setEventType(Notification::EVENT_TYPE_SHIPPING_PROVIDER_UPDATE)
            ->setProvider('Unifaun')
            ->setPayload(self::PAYLOAD);
    }

    private function management(): MerchantNotification
    {
        $responseFactory = $this->createMock(MerchantNotificationResponseInterfaceFactory::class);
        $responseFactory->method('create')->willReturnCallback(static fn () => new MerchantNotificationResponse());

        return new MerchantNotification(
            $this->linkRepository,
            $this->orderRepository,
            $this->createMock(LogManager::class),
            $responseFactory,
            $this->shippingUpdate,
            $this->lock
        );
    }
}
