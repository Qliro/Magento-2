<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Cron\PruneLog;
use Qliro\QliroOne\Service\Log\Pruner;

/**
 * @see \Qliro\QliroOne\Cron\PruneLog
 */
class PruneLogTest extends TestCase
{
    /**
     * The job runs the configured retention, nothing of its own, so it and the command agree.
     */
    public function testRunsTheConfiguredRetention(): void
    {
        $pruner = $this->createMock(Pruner::class);
        $pruner->expects(self::once())->method('prune')->with(null);

        (new PruneLog($pruner))->execute();
    }
}
