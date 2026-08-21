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
        $connection->startSetup();

        $connection->update(
            $this->moduleDataSetup->getTable(Link::TABLE_LINK),
            [LinkInterface::FIELD_QLIRO_ORDER_ID => null],
            [LinkInterface::FIELD_QLIRO_ORDER_ID . ' = ?' => 0]
        );

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
