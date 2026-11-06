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
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\Data\CheckoutStatusInterface;
use Qliro\QliroOne\Api\Data\CheckoutStatusResponseInterface as Response;
use Qliro\QliroOne\Api\Data\CheckoutStatusResponseInterfaceFactory;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\Data\QliroOrderInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\CheckoutStatus;
use Qliro\QliroOne\Model\Management\PlaceOrder;
use Qliro\QliroOne\Model\Management\QliroOrder;
use Qliro\QliroOne\Model\Notification\CheckoutStatusResponse;
use Qliro\QliroOne\Model\ResourceModel\Lock;

/**
 * @see \Qliro\QliroOne\Model\Management\CheckoutStatus::update
 */
class CheckoutStatusTest extends TestCase
{
    private const QLIRO_ORDER_ID = '900200';

    private LinkRepositoryInterface&MockObject $linkRepository;
    private MerchantInterface&MockObject $merchantApi;
    private PlaceOrder&MockObject $placeOrder;
    private QliroOrder&MockObject $qliroOrder;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private LogManager&MockObject $logManager;
    private Lock&MockObject $lock;

    protected function setUp(): void
    {
        $this->linkRepository = $this->createMock(LinkRepositoryInterface::class);
        $this->merchantApi = $this->createMock(MerchantInterface::class);
        $this->placeOrder = $this->createMock(PlaceOrder::class);
        $this->qliroOrder = $this->createMock(QliroOrder::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->logManager = $this->createMock(LogManager::class);
        $this->lock = $this->createMock(Lock::class);
        $this->lock->method('lock')->willReturn(true);
    }

    public function testNoLinkAtAllIsLinkNotFound(): void
    {
        $this->linkRepository->method('getByQliroOrderId')
            ->willThrowException(new NoSuchEntityException(__('no link')));
        $this->qliroOrder->expects(self::never())->method('cancel');

        $this->assertResponse($this->update(), 'Link not found', 404);
    }

    public function testInactiveLinkIsCanceledAndLinkNotFound(): void
    {
        $inactive = $this->link(null, CheckoutStatusInterface::STATUS_IN_PROCESS);
        $this->linkRepository->method('getByQliroOrderId')->willReturnCallback(
            fn ($id, $onlyActive = true) => $onlyActive
                ? throw new NoSuchEntityException(__('no active link'))
                : $inactive
        );
        $this->qliroOrder->expects(self::once())->method('cancel')->with(self::QLIRO_ORDER_ID);

        $this->assertResponse($this->update(), 'Link not found', 404);
    }

    public function testPlacementFailureNamesTheCauseAndLogsIt(): void
    {
        $this->linkRepository->method('getByQliroOrderId')->willReturn($this->link(null));
        $this->merchantApi->method('getOrder')->willReturn($this->createMock(QliroOrderInterface::class));
        $this->placeOrder->method('execute')->willThrowException(
            new TerminalException("Product that you are trying to add\nis not available.")
        );

        $this->logManager->expects(self::once())->method('critical')->with(
            'Order placement failed: ' . "Product that you are trying to add\nis not available.",
            self::callback(fn (array $context) => $context['extra']['qliro_order_id'] === self::QLIRO_ORDER_ID
                && $context['extra']['error'] === "Product that you are trying to add\nis not available.")
        );

        $this->assertResponse(
            $this->update(),
            'Order placement failed: Product that you are trying to add is not available.',
            500
        );
    }

    public function testPlacementSucceeds(): void
    {
        $this->linkRepository->method('getByQliroOrderId')->willReturn($this->link(null));
        $this->merchantApi->method('getOrder')->willReturn($this->createMock(QliroOrderInterface::class));
        $this->placeOrder->expects(self::once())->method('execute');

        $this->assertResponse($this->update(), 'received', 200);
    }

    public function testUnhandledStatusIsNamed(): void
    {
        $this->linkRepository->method('getByQliroOrderId')
            ->willReturn($this->link(42, CheckoutStatusInterface::STATUS_IN_PROCESS));
        $this->orderRepository->method('get')->willReturn($this->createMock(Order::class));
        $this->placeOrder->method('applyQliroOrderStatus')->willReturn(false);

        $this->assertResponse(
            $this->update(CheckoutStatusInterface::STATUS_IN_PROCESS),
            'Order status could not be applied: status InProcess',
            500
        );
    }

    public function testFailedApplyOfAHandledStatus(): void
    {
        $this->linkRepository->method('getByQliroOrderId')->willReturn($this->link(42));
        $this->orderRepository->method('get')->willReturn($this->createMock(Order::class));
        $this->placeOrder->method('applyQliroOrderStatus')->willReturn(false);

        $this->assertResponse($this->update(), 'Order status could not be applied', 500);
    }

    public function testMissingOrderIsStatusNotApplied(): void
    {
        $this->linkRepository->method('getByQliroOrderId')->willReturn($this->link(42));
        $this->orderRepository->method('get')
            ->willThrowException(new NoSuchEntityException(__('The entity that was requested doesn\'t exist.')));

        $this->assertResponse(
            $this->update(),
            'Order status could not be applied: The entity that was requested doesn\'t exist.',
            500
        );
    }

    public function testAppliedStatusIsReceived(): void
    {
        $this->linkRepository->method('getByQliroOrderId')->willReturn($this->link(42));
        $this->orderRepository->method('get')->willReturn($this->createMock(Order::class));
        $this->placeOrder->method('applyQliroOrderStatus')->willReturn(true);

        $this->assertResponse($this->update(), 'received', 200);
    }

    public function testLongDetailIsCut(): void
    {
        $this->linkRepository->method('getByQliroOrderId')->willReturn($this->link(null));
        $this->merchantApi->method('getOrder')->willThrowException(new \RuntimeException(str_repeat('x', 500)));

        $response = $this->update();

        self::assertSame('Order placement failed: ' . str_repeat('x', 200), $response->getCallbackResponse());
    }

    /**
     * @dataProvider orderIds
     */
    public function testLinkGoneUnderTheLockIsLinkNotFoundAndUnlocks(?int $orderId): void
    {
        $calls = 0;
        $this->linkRepository->method('getByQliroOrderId')->willReturnCallback(
            function ($id, $onlyActive = true) use (&$calls, $orderId) {
                if (!$onlyActive || ++$calls > 1) {
                    throw new NoSuchEntityException(__('gone'));
                }

                return $this->link($orderId);
            }
        );
        $this->lock->expects(self::once())->method('unlock')->with(self::QLIRO_ORDER_ID);

        $this->assertResponse($this->update(), 'Link not found', 404);
    }

    public static function orderIds(): array
    {
        return ['placed' => [42], 'not placed' => [null]];
    }

    public function testBusyLockIsPending(): void
    {
        $this->lock = $this->createMock(Lock::class);
        $this->lock->method('lock')->willReturn(false);
        $this->lock->expects(self::never())->method('unlock');
        $this->linkRepository->method('getByQliroOrderId')->willReturn($this->link(42));

        $this->assertResponse($this->update(), 'Order creation pending', 200);
    }

    public function testTooEarlyIsPendingWithoutLock(): void
    {
        $link = $this->createMock(LinkInterface::class);
        $link->method('getCreatedAt')->willReturn(date('Y-m-d H:i:s'));
        $this->linkRepository->method('getByQliroOrderId')->willReturn($link);
        $this->lock->expects(self::never())->method('lock');

        $this->assertResponse($this->update(), 'Order creation pending', 200);
    }

    public function testLookupErrorIsNotLinkNotFound(): void
    {
        $this->linkRepository->method('getByQliroOrderId')
            ->willThrowException(new \RuntimeException('SQLSTATE[HY000] gone away'));
        $this->qliroOrder->expects(self::never())->method('cancel');

        $this->assertResponse($this->update(), 'Order status could not be applied: database error, see the store log', 500);
    }

    /**
     * Qliro logs the answer, a duplicate key error quotes the row it collided with
     */
    public function testDatabaseErrorIsNamedButNotQuoted(): void
    {
        $previous = new \PDOException("SQLSTATE[23000]: Duplicate entry 'buyer@example.com' for key 'email'");
        $this->linkRepository->method('getByQliroOrderId')
            ->willThrowException(new \RuntimeException('could not save', 0, $previous));

        $response = $this->update();

        self::assertStringNotContainsString('buyer@example.com', (string)$response->getCallbackResponse());
    }

    public function testFailureUnlocks(): void
    {
        $this->linkRepository->method('getByQliroOrderId')->willReturn($this->link(null));
        $this->merchantApi->method('getOrder')->willThrowException(new \RuntimeException("bad \xC3 byte"));
        $this->lock->expects(self::once())->method('unlock');

        $response = $this->update();

        self::assertSame(500, $response->getCallbackResponseCode());
        self::assertNotFalse(json_encode($response->getCallbackResponse()));
    }

    private function update(string $status = CheckoutStatusInterface::STATUS_COMPLETED): Response
    {
        $push = $this->createMock(CheckoutStatusInterface::class);
        $push->method('getOrderId')->willReturn(self::QLIRO_ORDER_ID);
        $push->method('getStatus')->willReturn($status);

        $factory = $this->createMock(CheckoutStatusResponseInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(fn () => new CheckoutStatusResponse());

        $checkoutStatus = new CheckoutStatus(
            $this->merchantApi,
            $factory,
            $this->linkRepository,
            $this->orderRepository,
            $this->logManager,
            $this->lock,
            $this->placeOrder,
            $this->qliroOrder
        );

        return $checkoutStatus->update($push);
    }

    private function link(?int $orderId, string $status = CheckoutStatusInterface::STATUS_COMPLETED): LinkInterface
    {
        $link = $this->createMock(LinkInterface::class);
        $link->method('getQliroOrderId')->willReturn(self::QLIRO_ORDER_ID);
        $link->method('getOrderId')->willReturn($orderId);
        $link->method('getQliroOrderStatus')->willReturn($status);
        $link->method('getCreatedAt')->willReturn('2020-01-01 00:00:00');

        return $link;
    }

    private function assertResponse(Response $response, string $body, int $code): void
    {
        self::assertSame($body, $response->getCallbackResponse());
        self::assertSame($code, $response->getCallbackResponseCode());
    }
}
