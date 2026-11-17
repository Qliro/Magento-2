<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Method\QliroOne;

use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Api\ManagementInterface;
use Qliro\QliroOne\Model\Api\Client\Exception\OrderManagementApiException;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Link;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Method\QliroOne\Cancel;

class CancelTest extends TestCase
{
    /** @var ManagementInterface&MockObject */
    private $management;

    /** @var MessageManager&MockObject */
    private $messages;

    /** @var State&MockObject */
    private $appState;

    /** @var Order&MockObject */
    private $order;

    protected function setUp(): void
    {
        $this->management = $this->createMock(ManagementInterface::class);
        $this->messages = $this->createMock(MessageManager::class);
        $this->appState = $this->createMock(State::class);
        $this->order = $this->createMock(Order::class);
    }

    private function cancel(): Cancel
    {
        $link = $this->createMock(Link::class);
        $link->method('getQliroOrderId')->willReturn(5550001);
        $links = $this->createMock(LinkRepositoryInterface::class);
        $links->method('getByOrderId')->willReturn($link);

        return new Cancel(
            $links,
            $this->createMock(LogManager::class),
            $this->management,
            $this->messages,
            $this->appState
        );
    }

    private function subject(): array
    {
        // As Magento's gateway passes it: the order is an adapter, the payment carries the order itself
        $adapter = $this->createMock(OrderAdapterInterface::class);
        $adapter->method('getId')->willReturn(42);
        $payment = $this->createMock(Payment::class);
        $payment->method('getOrder')->willReturn($this->order);
        $subject = $this->createMock(PaymentDataObjectInterface::class);
        $subject->method('getOrder')->willReturn($adapter);
        $subject->method('getPayment')->willReturn($payment);

        return ['payment' => $subject];
    }

    private function refusal(): TerminalException
    {
        // The chain QliroOrder::cancel throws: its own wrapper around the client's explained refusal
        return new TerminalException(
            'Couldn\'t request to cancel QliroOne order.',
            0,
            new OrderManagementApiException(__('Error [%1]: %2', 'NO_ITEMS_TO_CANCEL', 'Nothing left to cancel'))
        );
    }

    public function testRefusedCancelIsRecordedOnTheOrderAndShownToTheAdmin(): void
    {
        $this->management->method('cancelQliroOrder')->willThrowException($this->refusal());
        $this->appState->method('getAreaCode')->willReturn('adminhtml');
        $this->order->expects($this->once())->method('addCommentToStatusHistory')
            ->with($this->callback(static function ($message): bool {
                return str_contains((string)$message, 'Qliro did not cancel the payment')
                    && str_contains((string)$message, 'Error [NO_ITEMS_TO_CANCEL]: Nothing left to cancel. Cancel');
            }));
        $this->messages->expects($this->once())->method('addWarningMessage')
            ->with($this->callback(static function ($message): bool {
                return str_contains((string)$message, 'NO_ITEMS_TO_CANCEL');
            }));

        $this->assertNull($this->cancel()->execute($this->subject()));
    }

    public function testRefusedCancelOutsideTheAdminOnlyLeavesTheComment(): void
    {
        $this->management->method('cancelQliroOrder')->willThrowException($this->refusal());
        $this->appState->method('getAreaCode')->willReturn('webapi_rest');
        $this->order->expects($this->once())->method('addCommentToStatusHistory');
        $this->messages->expects($this->never())->method('addWarningMessage');

        $this->cancel()->execute($this->subject());
    }

    public function testRefusedCancelWithoutAnAreaStillLeavesTheComment(): void
    {
        $this->management->method('cancelQliroOrder')->willThrowException($this->refusal());
        $this->appState->method('getAreaCode')->willThrowException(new LocalizedException(__('Area code is not set')));
        $this->order->expects($this->once())->method('addCommentToStatusHistory');
        $this->messages->expects($this->never())->method('addWarningMessage');

        $this->cancel()->execute($this->subject());
    }

    public function testAcceptedCancelAddsNoWarning(): void
    {
        $this->management->expects($this->once())->method('cancelQliroOrder')->with(5550001);
        $this->order->expects($this->never())->method('addCommentToStatusHistory');
        $this->messages->expects($this->never())->method('addWarningMessage');

        $this->cancel()->execute($this->subject());
    }
}
