<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Service\Log;

use Magento\Framework\Stdlib\DateTime\DateTime;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\ResourceModel\LogRecord;

/**
 * Prunes the qliroone_log table down to the configured retention, for the cron job and the console command alike
 */
class Pruner
{
    /**
     * @param Config $config
     * @param LogRecord $logRecord
     * @param LogManager $logManager
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly Config     $config,
        private readonly LogRecord  $logRecord,
        private readonly LogManager $logManager,
        private readonly DateTime   $dateTime
    ) {
    }

    /**
     * Delete the rows older than the retention window
     *
     * @param int|null $retentionDays Overrides the configured window, 0 keeps every row
     * @return int Rows deleted
     */
    public function prune(?int $retentionDays = null): int
    {
        $retentionDays = $this->resolveRetentionDays($retentionDays);

        if ($retentionDays <= 0) {
            return 0;
        }

        $before = $this->cutOff($retentionDays);
        $deleted = $this->logRecord->deleteOlderThan($before);

        if ($deleted > 0) {
            $remaining = $this->logRecord->hasRowsOlderThan($before);

            $this->logManager->info(
                sprintf(
                    'Pruned %d log rows older than %d days%s',
                    $deleted,
                    $retentionDays,
                    $remaining ? ', the batch cap was reached and more remain' : ''
                ),
                [
                    'extra' => [
                        'before' => $before,
                        'retention_days' => $retentionDays,
                        'rows_remain' => $remaining,
                    ],
                ]
            );
        }

        return $deleted;
    }

    /**
     * Whether a window still has rows behind it, so a caller can say the backlog is not worked off
     *
     * @param int $retentionDays
     * @return bool
     */
    public function hasBacklog(int $retentionDays): bool
    {
        return $retentionDays > 0 && $this->logRecord->hasRowsOlderThan($this->cutOff($retentionDays));
    }

    /**
     * @param int $retentionDays
     * @return string
     */
    private function cutOff(int $retentionDays): string
    {
        return (string)$this->dateTime->gmtDate(null, time() - $retentionDays * 86400);
    }

    /**
     * The window a run is held to: the one given, else the configured one, never beyond what a date can hold
     *
     * @param int|null $retentionDays
     * @return int Days, 0 keeps every row
     */
    public function resolveRetentionDays(?int $retentionDays): int
    {
        $retentionDays = $retentionDays ?? $this->config->getLogRetentionDays();

        return max(0, min($retentionDays, Config::MAX_LOG_RETENTION_DAYS));
    }
}
