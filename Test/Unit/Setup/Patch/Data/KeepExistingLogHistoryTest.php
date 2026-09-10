<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Setup\Patch\Data\KeepExistingLogHistory;

/**
 * @see \Qliro\QliroOne\Setup\Patch\Data\KeepExistingLogHistory
 */
class KeepExistingLogHistoryTest extends TestCase
{
    /**
     * A fresh install has no history to lose, so it is opted into the window the ticket asked for.
     */
    public function testOptsAFreshInstallIntoTheDaysDefault(): void
    {
        $configWriter = $this->createMock(WriterInterface::class);
        $configWriter->expects(self::once())
            ->method('save')
            ->with(
                Config::XML_PATH_LOG_RETENTION_DAYS,
                (string)Config::FRESH_INSTALL_LOG_RETENTION_DAYS
            );

        $patch = new KeepExistingLogHistory($this->moduleDataSetup(false), $configWriter);

        self::assertSame($patch, $patch->apply());
    }

    /**
     * A store that already has log history keeps every row of it, which is what the shipped
     * default says, so the patch writes nothing and the merchant picks a window when they want one.
     */
    public function testLeavesAStoreWithHistoryKeepingIt(): void
    {
        $configWriter = $this->createMock(WriterInterface::class);
        $configWriter->expects(self::never())->method('save');

        (new KeepExistingLogHistory($this->moduleDataSetup('1'), $configWriter))->apply();
    }

    /**
     * The question is whether a row exists, asked as a lookup, so a row of any id counts and the
     * multi million row tables this feature exists for are not scanned during the upgrade.
     *
     * @param mixed $found
     * @return ModuleDataSetupInterface&MockObject
     */
    private function moduleDataSetup(mixed $found): ModuleDataSetupInterface&MockObject
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->expects(self::once())->method('limit')->with(1)->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn($found);

        $moduleDataSetup = $this->createMock(ModuleDataSetupInterface::class);
        $moduleDataSetup->method('getConnection')->willReturn($connection);
        $moduleDataSetup->method('getTable')->with('qliroone_log')->willReturn('prefix_qliroone_log');

        return $moduleDataSetup;
    }
}
