<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\OrderManagementStatus\Update;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\OrderManagementStatus\Update\CaptureHold;

/**
 * A failed capture holds the order, and only that capture succeeding releases it. Magento's own
 * hold() and unhold() run, so the status from before the hold is what comes back.
 *
 * @see \Qliro\QliroOne\Model\OrderManagementStatus\Update\CaptureHold
 */
class CaptureHoldTest extends TestCase
{
    /** @var array<string, string[]> */
    private array $assigned = [
        Order::STATE_PROCESSING => ['processing', 'erp_warehouse'],
        Order::STATE_HOLDED => ['holded'],
        Order::STATE_COMPLETE => ['complete'],
    ];

    /** @var string[] */
    private array $comments = [];

    public function testASuccessOfAnotherCaptureKeepsTheHold(): void
    {
        $order = $this->order();
        $hold = new CaptureHold();

        $hold->hold($order, 555, 'Error');
        $hold->release($order, 556);

        self::assertSame(Order::STATE_HOLDED, $order->getState());
        self::assertSame(['555'], $order->getPayment()->getAdditionalInformation(CaptureHold::KEY_FAILED));
        self::assertSame('Capture 556 succeeded, the order stays on hold: capture 555 is still failed', end($this->comments));
    }

    public function testARedeliveredSuccessAddsNoSecondComment(): void
    {
        $order = $this->order();
        $hold = new CaptureHold();

        $hold->hold($order, 555, 'Error');
        $hold->release($order, 556);
        $hold->release($order, 556);

        self::assertCount(1, array_filter($this->comments, fn (string $c) => str_contains($c, 'still failed')));
    }

    public function testTheFailedCaptureSucceedingReleasesTheHoldWithTheEarlierStatus(): void
    {
        $order = $this->order();
        $hold = new CaptureHold();

        $hold->hold($order, 555, 'Error');
        $hold->release($order, 556);
        $hold->release($order, 555);

        self::assertSame(Order::STATE_PROCESSING, $order->getState());
        self::assertSame('erp_warehouse', $order->getStatus());
        self::assertNull($order->getPayment()->getAdditionalInformation(CaptureHold::KEY_FAILED));
        self::assertSame('Capture 555 succeeded, the order is released from hold', end($this->comments));
    }

    public function testEveryFailedCaptureMustSucceedFirst(): void
    {
        $order = $this->order();
        $hold = new CaptureHold();

        $hold->hold($order, 555, 'Error');
        $hold->hold($order, 556, 'UserInteraction');
        $hold->release($order, 555);

        self::assertSame(Order::STATE_HOLDED, $order->getState());

        $hold->release($order, 556);

        self::assertSame('erp_warehouse', $order->getStatus());
    }

    public function testAHoldTheMerchantPlacedIsNotReleased(): void
    {
        $order = $this->order();
        $order->hold();
        $hold = new CaptureHold();

        $hold->hold($order, 555, 'Error');
        $hold->release($order, 555);

        self::assertSame(Order::STATE_HOLDED, $order->getState());
        self::assertSame(
            'Capture 555 succeeded, the order stays on hold: Qliro One did not place this hold',
            end($this->comments)
        );
    }

    public function testAHoldFromBeforeTheTrackingIsNotReleased(): void
    {
        $order = $this->order();
        $order->hold();

        (new CaptureHold())->release($order, 555);

        self::assertSame(Order::STATE_HOLDED, $order->getState());
    }

    public function testAnErrorOnAnOrderThatCannotBeHeldSaysWhichCaptureFailed(): void
    {
        $order = $this->order();
        $order->setState(Order::STATE_COMPLETE)->setStatus('complete');

        self::assertTrue((new CaptureHold())->hold($order, 555, 'Error'));
        self::assertSame(Order::STATE_COMPLETE, $order->getState());
        self::assertSame(
            'Qliro One reported Error for capture 555, the order cannot be held in state complete',
            end($this->comments)
        );
    }

    public function testARepeatedErrorOnAHeldOrderKeepsOneFailure(): void
    {
        $order = $this->order();
        $hold = new CaptureHold();

        $hold->hold($order, 555, 'UserInteraction');
        $hold->hold($order, 555, 'Error');
        $hold->release($order, 555);

        self::assertSame('erp_warehouse', $order->getStatus());
    }

    public function testAnOrderReleasedByHandEndsTheEarlierFailure(): void
    {
        $order = $this->order();
        $hold = new CaptureHold();

        $hold->hold($order, 555, 'Error');
        $order->unhold();
        // What OrderHoldPlugin does on a release by hand
        $hold->forget($order);
        $hold->hold($order, 556, 'Error');
        $hold->release($order, 556);

        self::assertSame(Order::STATE_PROCESSING, $order->getState());
    }

    public function testAnErrorAfterTheSameCaptureSucceededIsIgnored(): void
    {
        $order = $this->order();
        $hold = new CaptureHold();

        $hold->release($order, 555);

        self::assertFalse($hold->hold($order, 555, 'Error'));
        self::assertSame(Order::STATE_PROCESSING, $order->getState());
    }

    public function testAnEarlierStatusNoLongerAssignedGivesTheStateDefaultAndSaysSo(): void
    {
        $order = $this->order();
        $hold = new CaptureHold();

        $hold->hold($order, 555, 'Error');
        $this->assigned[Order::STATE_PROCESSING] = ['processing'];
        $hold->release($order, 555);

        self::assertSame('processing', $order->getStatus());
        self::assertSame(
            'The status erp_warehouse the order had before the hold is not assigned to state processing, so the order gets processing',
            end($this->comments)
        );
    }

    public function testAShipmentCaptureHealingTheFailureKeepsTheEarlierStatusOverTheInvoices(): void
    {
        $order = $this->order();
        $hold = new CaptureHold();
        $hold->hold($order, 555, 'Error');

        $lifted = $hold->lift($order);
        // The invoice sets the state's default status
        $order->setState(Order::STATE_PROCESSING)->setStatus('processing');
        $hold->release($order, 555, $lifted);

        self::assertSame(Order::STATE_PROCESSING, $order->getState());
        self::assertSame('erp_warehouse', $order->getStatus());
    }

    public function testAShipmentCaptureOfAnotherLineBringsTheHoldBack(): void
    {
        $order = $this->order();
        $hold = new CaptureHold();
        $hold->hold($order, 555, 'Error');

        $lifted = $hold->lift($order);
        $order->setState(Order::STATE_PROCESSING)->setStatus('processing');
        $hold->release($order, 556, $lifted);

        self::assertSame(Order::STATE_HOLDED, $order->getState());
        self::assertSame('erp_warehouse', $order->getHoldBeforeStatus());
    }

    public function testAHoldThatCannotComeBackSaysWhichCaptureIsStillFailed(): void
    {
        $order = $this->order();
        $hold = new CaptureHold();
        $hold->hold($order, 555, 'Error');

        $lifted = $hold->lift($order);
        $order->setState(Order::STATE_COMPLETE)->setStatus('complete');
        $hold->release($order, 556, $lifted);

        self::assertSame(Order::STATE_COMPLETE, $order->getState());
        self::assertContains(
            'The order could not be put back on hold in state complete: capture 555 is still failed',
            $this->comments
        );
        self::assertNull($order->getPayment()->getAdditionalInformation(CaptureHold::KEY_FAILED));
    }

    public function testLiftLeavesAnOrderNotOnHoldAlone(): void
    {
        self::assertNull((new CaptureHold())->lift($this->order()));
    }

    /** A processing order an ERP moved to its own status */
    private function order(): Order
    {
        $payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $config = $this->createMock(Config::class);
        $config->method('getStateDefaultStatus')->willReturnArgument(0);
        $config->method('getStateStatuses')->willReturnCallback(
            fn (string $state): array => array_fill_keys($this->assigned[$state] ?? [], 'label')
        );

        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPayment', 'getConfig', 'addStatusHistoryComment'])
            ->getMock();
        $order->method('getPayment')->willReturn($payment);
        $order->method('getConfig')->willReturn($config);
        $order->method('addStatusHistoryComment')->willReturnCallback(function ($comment) use ($order) {
            $this->comments[] = (string)$comment;

            return $order;
        });
        $order->setState(Order::STATE_PROCESSING)->setStatus('erp_warehouse');

        return $order;
    }
}
