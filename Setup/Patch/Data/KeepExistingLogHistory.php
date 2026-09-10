<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Setup\Patch\Data;

use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ResourceModel\LogRecord;

/**
 * Opts a fresh install into the 30 day log retention, and leaves a store with history keeping it
 *
 * The shipped default keeps every row, because the code is deployed before this patch runs and a
 * nightly prune in that window would delete history nobody asked it to. An install whose log table
 * is empty has nothing to lose, so it is opted into the 30 days the ticket asked for; one with rows
 * keeps them until the merchant picks a window.
 */
class KeepExistingLogHistory implements DataPatchInterface
{
    /**
     * Class constructor
     *
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param WriterInterface $configWriter
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup,
        private readonly WriterInterface $configWriter
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable(LogRecord::TABLE_LOG);

        // A lookup, not a count: this runs in maintenance on the very tables this feature exists for
        $hasHistory = (bool)$connection->fetchOne(
            $connection->select()->from($table, new \Zend_Db_Expr('1'))->limit(1)
        );

        // An install with history keeps it, the shipped default already says so and is left alone
        if (!$hasHistory) {
            $this->configWriter->save(
                Config::XML_PATH_LOG_RETENTION_DAYS,
                (string)Config::FRESH_INSTALL_LOG_RETENTION_DAYS
            );
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
