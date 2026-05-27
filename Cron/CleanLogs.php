<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Cron;

use Qliro\QliroOne\Api\Data\LogRecordInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\ConnectionProvider;
use Qliro\QliroOne\Model\ResourceModel\LogRecord as LogRecordResource;

/**
 * Nightly cron job that removes Qliro log records older than the configured retention period
 * from the qliroone_log database table.
 */
readonly class CleanLogs
{
    /**
     * Class constructor
     *
     * @param Config               $qliroConfig
     * @param ConnectionProvider   $connectionProvider
     */
    public function __construct(
        private Config             $qliroConfig,
        private ConnectionProvider $connectionProvider
    ) {
    }

    /**
     * Entry point called by the Magento cron scheduler.
     */
    public function execute(): void
    {
        $retentionDays = $this->qliroConfig->getLogRetentionDays();
        if ($retentionDays <= 0) {
            return;
        }

        $cutoff = new \DateTimeImmutable("-{$retentionDays} days", new \DateTimeZone('UTC'));

        $connection = $this->connectionProvider->getConnection();
        $connection->delete(
            LogRecordResource::TABLE_LOG,
            [sprintf('%s < ?', LogRecordInterface::FIELD_DATE) => $cutoff->format('Y-m-d H:i:s')]
        );
    }
}
