<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Cron\ReconcileCaptures;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\OrderManagementStatus;
use Qliro\QliroOne\Model\OrderManagementStatus\CaptureReconciler;
use Qliro\QliroOne\Model\ResourceModel\OrderManagementStatus\UnconfirmedCaptures;

/**
 * @see \Qliro\QliroOne\Cron\ReconcileCaptures
 */
class ReconcileCapturesTest extends TestCase
{
    /**
     * Only captures requested in the last days are read, past a grace period for their callback, so the job never sweeps the
     * whole history, a capture that fails does not hold up the rest, and one not applied is logged
     */
    public function testReconcilesRecentlyReportedCapturesAndGoesOnAfterAFailure(): void
    {
        $captures = $this->createMock(UnconfirmedCaptures::class);
        $captures->expects(self::once())->method('due')->with(3 * 86400, 15 * 60, 50)->willReturn([5, 9]);

        $rows = [5 => $this->createMock(OrderManagementStatus::class), 9 => $this->createMock(OrderManagementStatus::class)];
        $repository = $this->createMock(OrderManagementStatusRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(fn ($id) => $rows[$id]);

        $reconciled = [];
        $reconciler = $this->createMock(CaptureReconciler::class);
        $reconciler->method('reconcile')->willReturnCallback(function ($row) use (&$reconciled, $rows) {
            $reconciled[] = array_search($row, $rows, true);
            if ($row === $rows[5]) {
                throw new \RuntimeException('Qliro timed out');
            }
            return false;
        });

        $logManager = $this->createMock(LogManager::class);
        $logManager->expects(self::once())->method('warning');
        $logManager->expects(self::once())->method('info')->with(self::stringContains('not applied'));

        (new ReconcileCaptures($captures, $repository, $reconciler, $logManager))->execute();

        self::assertSame([5, 9], $reconciled);
    }
}
