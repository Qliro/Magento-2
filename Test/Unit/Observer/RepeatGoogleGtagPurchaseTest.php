<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Framework\View\LayoutInterface;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Observer\RepeatGoogleGtagPurchase;

/**
 * @see \Qliro\QliroOne\Observer\RepeatGoogleGtagPurchase::execute
 */
class RepeatGoogleGtagPurchaseTest extends TestCase
{
    public function testGivesTheGa4BlockTheOrder(): void
    {
        $block = new DataObject();
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('getBlock')->with('google_gtag_analytics')->willReturn($block);

        (new RepeatGoogleGtagPurchase($layout))->execute($this->observer([42]));

        self::assertSame([42], $block->getOrderIds());
    }

    public function testLeavesAStoreWithoutTheGa4BlockAlone(): void
    {
        $layout = $this->createMock(LayoutInterface::class);
        $layout->method('getBlock')->willReturn(false);

        (new RepeatGoogleGtagPurchase($layout))->execute($this->observer([42]));

        $this->addToAssertionCount(1);
    }

    public function testIgnoresAnEventWithoutOrderIds(): void
    {
        $layout = $this->createMock(LayoutInterface::class);
        $layout->expects(self::never())->method('getBlock');

        (new RepeatGoogleGtagPurchase($layout))->execute($this->observer([]));
    }

    private function observer(array $orderIds): Observer
    {
        return new Observer(['event' => new Event(['order_ids' => $orderIds])]);
    }
}
