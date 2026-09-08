<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Cron;

use Qliro\QliroOne\Service\Log\Pruner;

/**
 * Cron job that prunes the qliroone_log table, see etc/crontab.xml
 */
class PruneLog
{
    /**
     * @param Pruner $pruner
     */
    public function __construct(
        private readonly Pruner $pruner
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        $this->pruner->prune();
    }
}
