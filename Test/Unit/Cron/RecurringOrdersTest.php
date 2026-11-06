<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Cron;

use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\App\EmulationFactory;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\RecurringInfoRepositoryInterface;
use Qliro\QliroOne\Cron\RecurringOrders;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\RecurringInfo;
use Qliro\QliroOne\Model\ResourceModel\RecurringInfo as RecurringInfoResource;
use Qliro\QliroOne\Service\RecurringPayments\PlaceOrders;

/**
 * @see \Qliro\QliroOne\Cron\RecurringOrders::placeOrders
 */
class RecurringOrdersTest extends TestCase
{
    /**
     * A full save of the row loaded before the order was placed would undo a cancel made meanwhile
     */
    public function testOnlyTheNextOrderDateIsWrittenBack(): void
    {
        $resource = $this->createMock(RecurringInfoResource::class);
        $resource->method('getIdFieldName')->willReturn('entity_id');
        $info = new RecurringInfo($this->createMock(Context::class), $this->createMock(Registry::class), $resource);
        $info->setData(['entity_id' => 5, 'original_order_id' => 10, 'next_order_date' => '2026-11-05']);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn([$store]);
        $config = $this->createMock(Config::class);
        $config->method('isUseRecurring')->willReturn(true);
        $repo = $this->createMock(RecurringInfoRepositoryInterface::class);
        $repo->method('getByTodaysDate')->willReturn([$info]);
        $repo->expects(self::never())->method('save');
        $placeOrders = $this->createMock(PlaceOrders::class);
        $placeOrders->method('placeRecurringOrders')
            ->willReturnCallback(fn (array $infos) => $infos[0]->setNextOrderDate('2026-12-05'));
        $emulations = $this->createMock(EmulationFactory::class);
        $emulations->method('create')->willReturn($this->createMock(Emulation::class));
        $resource->expects(self::once())->method('updateNextOrderDate')->with(5, '2026-12-05');

        (new RecurringOrders($storeManager, $config, $placeOrders, $repo, $emulations, $resource))->placeOrders();
    }
}
