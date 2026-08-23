<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Model\ResourceModel\Link;

/**
 * Replace the zeroes left in qliro_order_id by links that never got a Qliro order with null,
 * so that a lookup with an empty value can no longer match them
 */
class ClearZeroQliroOrderId implements DataPatchInterface
{
    /**
     * Rows per statement, the table holds one row per checkout the shop ever started
     */
    private const BATCH_SIZE = 5000;

    /**
     * Class constructor
     *
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    /**
     * @inheritDoc
     */
    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable(Link::TABLE_LINK);
        $connection->startSetup();

        // Walked in batches: the zeroes are the abandoned checkouts, so on a long lived shop
        // they are most of the table and one statement would hold it for the whole upgrade
        do {
            $ids = $connection->fetchCol(
                $connection->select()
                    ->from($table, LinkInterface::FIELD_ID)
                    ->where(LinkInterface::FIELD_QLIRO_ORDER_ID . ' = ?', 0)
                    ->order(LinkInterface::FIELD_ID . ' ASC')
                    ->limit(self::BATCH_SIZE)
            );

            if (!$ids) {
                break;
            }

            $connection->update(
                $table,
                [LinkInterface::FIELD_QLIRO_ORDER_ID => null],
                [LinkInterface::FIELD_ID . ' IN (?)' => $ids]
            );
        } while (\count($ids) === self::BATCH_SIZE);

        $connection->endSetup();

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
