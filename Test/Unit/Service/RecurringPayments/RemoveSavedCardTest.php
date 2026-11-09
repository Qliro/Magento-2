<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Service\RecurringPayments;

use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Sales\Api\Data\OrderStatusHistoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\RecurringInfo;
use Qliro\QliroOne\Model\ResourceModel\RecurringInfo as RecurringInfoResource;
use Qliro\QliroOne\Service\RecurringPayments\RemoveSavedCard;

/**
 * @see \Qliro\QliroOne\Service\RecurringPayments\RemoveSavedCard
 */
class RemoveSavedCardTest extends TestCase
{
    private MerchantInterface&MockObject $merchantApi;
    private RecurringInfoResource&MockObject $resource;
    private LogManager&MockObject $logManager;
    private OrderRepositoryInterface&MockObject $orderRepo;
    private OrderStatusHistoryRepositoryInterface&MockObject $historyRepo;
    private Order&MockObject $order;

    protected function setUp(): void
    {
        $this->merchantApi = $this->createMock(MerchantInterface::class);
        $this->resource = $this->createMock(RecurringInfoResource::class);
        $this->logManager = $this->createMock(LogManager::class);
        $this->orderRepo = $this->createMock(OrderRepositoryInterface::class);
        $this->historyRepo = $this->createMock(OrderStatusHistoryRepositoryInterface::class);
        $this->order = $this->createMock(Order::class);
        $this->order->method('getStoreId')->willReturn(2);
        $this->orderRepo->method('get')->with(10)->willReturn($this->order);
    }

    public function testACancelledSubscriptionCardIsDeletedAndNoted(): void
    {
        $info = $this->info('card-1', '2026-11-01');
        $this->merchantApi->expects(self::once())->method('removeSavedCreditCard')
            ->with('card-1', 2)->willReturn(true);
        $this->resource->expects(self::once())->method('markSavedCardRemoved')->with(5)->willReturn(1);
        $history = $this->createMock(OrderStatusHistoryInterface::class);
        $this->order->expects(self::once())->method('addCommentToStatusHistory')
            ->with('The customer removed the saved card of this subscription. Qliro deleted it.')
            ->willReturn($history);
        $this->historyRepo->expects(self::once())->method('save')->with($history);
        $this->logManager->expects(self::never())->method('notice');

        self::assertSame(RemoveSavedCard::REMOVED, $this->service()->execute($info));
        self::assertNotEmpty($info->getSavedCreditCardRemovedAt());
        self::assertSame('card-1', $info->getSavedCreditCardId());
    }

    public function testACardQliroNoLongerHadIsNotedAsSuch(): void
    {
        $this->merchantApi->method('removeSavedCreditCard')->willReturn(false);
        $this->resource->method('markSavedCardRemoved')->willReturn(1);
        $this->order->expects(self::once())->method('addCommentToStatusHistory')
            ->with('The customer removed the saved card of this subscription. Qliro no longer had it.')
            ->willReturn($this->createMock(OrderStatusHistoryInterface::class));
        $this->logManager->expects(self::once())->method('notice');

        self::assertSame(RemoveSavedCard::REMOVED, $this->service()->execute($this->info('card-1', '2026-11-01')));
    }

    public function testASecondRequestDoesNotNoteTheRemovalAgain(): void
    {
        $this->merchantApi->method('removeSavedCreditCard')->willReturn(false);
        $this->resource->method('markSavedCardRemoved')->willReturn(0);
        $this->order->expects(self::never())->method('addCommentToStatusHistory');

        self::assertSame(RemoveSavedCard::REMOVED, $this->service()->execute($this->info('card-1', '2026-11-01')));
    }

    public function testAFailedCommentDoesNotFailTheRemoval(): void
    {
        $this->merchantApi->method('removeSavedCreditCard')->willReturn(true);
        $this->resource->method('markSavedCardRemoved')->willReturn(1);
        $this->order->method('addCommentToStatusHistory')
            ->willReturn($this->createMock(OrderStatusHistoryInterface::class));
        $this->historyRepo->method('save')->willThrowException(new \RuntimeException('db'));
        $this->logManager->expects(self::once())->method('critical');

        self::assertSame(RemoveSavedCard::REMOVED, $this->service()->execute($this->info('card-1', '2026-11-01')));
    }

    public function testAnOrderWithoutAStoreIsRefusedBeforeQliroIsCalled(): void
    {
        $order = $this->createMock(Order::class);
        $order->method('getStoreId')->willReturn(null);
        $orderRepo = $this->createMock(OrderRepositoryInterface::class);
        $orderRepo->method('get')->willReturn($order);
        $this->orderRepo = $orderRepo;
        $this->merchantApi->expects(self::never())->method('removeSavedCreditCard');

        $this->expectException(\Magento\Framework\Exception\LocalizedException::class);

        $this->service()->execute($this->info('card-1', '2026-11-01'));
    }

    public function testAnActiveSubscriptionKeepsItsCard(): void
    {
        $this->merchantApi->expects(self::never())->method('removeSavedCreditCard');
        $this->resource->expects(self::never())->method('markSavedCardRemoved');

        self::assertSame(RemoveSavedCard::NOT_CANCELED, $this->service()->execute($this->info('card-1', null)));
    }

    public function testASubscriptionWithoutACardHasNothingToRemove(): void
    {
        $this->merchantApi->expects(self::never())->method('removeSavedCreditCard');

        self::assertSame(RemoveSavedCard::NO_SAVED_CARD, $this->service()->execute($this->info(null, '2026-11-01')));
    }

    public function testARemovedCardIsNotSentToQliroAgain(): void
    {
        $info = $this->info('card-1', '2026-11-01');
        $info->setSavedCreditCardRemovedAt('2026-11-02 10:00:00');
        $this->merchantApi->expects(self::never())->method('removeSavedCreditCard');
        $this->resource->expects(self::never())->method('markSavedCardRemoved');

        self::assertSame(RemoveSavedCard::REMOVED, $this->service()->execute($info));
    }

    public function testAQliroFailureLeavesTheCardUnmarked(): void
    {
        $info = $this->info('card-1', '2026-11-01');
        $this->merchantApi->method('removeSavedCreditCard')->willThrowException(new \RuntimeException('down'));
        $this->resource->expects(self::never())->method('markSavedCardRemoved');

        try {
            $this->service()->execute($info);
            self::fail('The failure must reach the caller');
        } catch (\RuntimeException $e) {
            self::assertNull($info->getSavedCreditCardRemovedAt());
        }
    }

    private function info(?string $savedCardId, ?string $canceledDate): RecurringInfo
    {
        $info = $this->recurringInfo();
        $info->setData([
            'entity_id' => 5,
            'original_order_id' => 10,
            'saved_credit_card_id' => $savedCardId,
            'canceled_date' => $canceledDate,
        ]);

        return $info;
    }

    private function service(): RemoveSavedCard
    {
        return new RemoveSavedCard(
            $this->merchantApi,
            $this->resource,
            $this->orderRepo,
            $this->historyRepo,
            $this->logManager
        );
    }

    private function recurringInfo(): RecurringInfo
    {
        $resource = $this->createMock(RecurringInfoResource::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');

        return new RecurringInfo($this->createMock(Context::class), $this->createMock(Registry::class), $resource);
    }
}
