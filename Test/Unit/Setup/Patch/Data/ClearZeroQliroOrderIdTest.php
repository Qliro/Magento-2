<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Setup\Patch\Data;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Setup\Patch\Data\ClearZeroQliroOrderId;

/**
 * @see \Qliro\QliroOne\Setup\Patch\Data\ClearZeroQliroOrderId
 *
 * PLIN-378: the qliro_order_id column used to be non nullable, so links that never got a Qliro
 * order hold a zero. Those zeroes are what an empty lookup value matched, so they are cleared.
 */
class ClearZeroQliroOrderIdTest extends TestCase
{
    /**
     * The zeroes left behind become null, addressed by id so that only they are touched.
     */
    public function testReplacesZeroesWithNull(): void
    {
        $connection = $this->connection([[7, 9]]);
        $connection->expects(self::once())
            ->method('update')
            ->with(
                'prefix_qliroone_link',
                ['qliro_order_id' => null],
                ['link_id IN (?)' => [7, 9]]
            );

        $patch = new ClearZeroQliroOrderId($this->moduleDataSetup($connection));

        self::assertSame($patch, $patch->apply());
    }

    /**
     * A table with nothing to clear is not written to at all.
     */
    public function testWritesNothingWhenThereAreNoZeroes(): void
    {
        $connection = $this->connection([[]]);
        $connection->expects(self::never())->method('update');

        $patch = new ClearZeroQliroOrderId($this->moduleDataSetup($connection));

        $patch->apply();
    }

    /**
     * A full batch means there may be more, so the patch keeps going until a short one arrives.
     * One statement over the whole table would hold it for the length of the upgrade.
     */
    public function testKeepsGoingWhileBatchesComeBackFull(): void
    {
        $fullBatch = \range(1, 5000);
        $connection = $this->connection([$fullBatch, [5001]]);
        $connection->expects(self::exactly(2))->method('update');

        $patch = new ClearZeroQliroOrderId($this->moduleDataSetup($connection));

        $patch->apply();
    }

    /**
     * An adapter whose select returns the given id batches in order
     *
     * @param array<int, array<int, int>> $batches
     * @return AdapterInterface&MockObject
     */
    private function connection(array $batches): AdapterInterface&MockObject
    {
        $connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->method('order')->willReturnSelf();
        $select->method('limit')->willReturnSelf();

        $connection->method('select')->willReturn($select);
        $connection->method('fetchCol')->willReturnOnConsecutiveCalls(...$batches);

        return $connection;
    }

    /**
     * @param AdapterInterface&MockObject $connection
     * @return ModuleDataSetupInterface&MockObject
     */
    private function moduleDataSetup(AdapterInterface $connection): ModuleDataSetupInterface&MockObject
    {
        $moduleDataSetup = $this->createMock(ModuleDataSetupInterface::class);
        $moduleDataSetup->method('getConnection')->willReturn($connection);
        $moduleDataSetup->method('getTable')->with('qliroone_link')->willReturn('prefix_qliroone_link');

        return $moduleDataSetup;
    }
}
