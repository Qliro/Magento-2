<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Setup\Patch\Data;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
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
     * The zeroes left behind become null, and only they are touched.
     */
    public function testReplacesZeroesWithNull(): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->expects(self::once())
            ->method('update')
            ->with(
                'prefix_qliroone_link',
                ['qliro_order_id' => null],
                ['qliro_order_id = ?' => 0]
            );

        $moduleDataSetup = $this->createMock(ModuleDataSetupInterface::class);
        $moduleDataSetup->method('getConnection')->willReturn($connection);
        $moduleDataSetup->method('getTable')->with('qliroone_link')->willReturn('prefix_qliroone_link');

        $patch = new ClearZeroQliroOrderId($moduleDataSetup);

        self::assertSame($patch, $patch->apply());
    }
}
