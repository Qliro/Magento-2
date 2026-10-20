<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Cron;

use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Cron\ReconcileShipmentCaptures;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\OrderManagementStatus;
use Qliro\QliroOne\Model\OrderManagementStatus\ShipmentCaptureReconciler;
use Qliro\QliroOne\Model\ResourceModel\OrderManagementStatus\UnconfirmedShipmentCaptures;

/**
 * @see \Qliro\QliroOne\Cron\ReconcileShipmentCaptures
 */
class ReconcileShipmentCapturesTest extends TestCase
{
    /**
     * Only captures Qliro reported on in the last days are read, so the job never sweeps the
     * whole history, a capture that fails does not hold up the rest, and one not applied is logged
     */
    public function testReconcilesRecentlyReportedCapturesAndGoesOnAfterAFailure(): void
    {
        $captures = $this->createMock(UnconfirmedShipmentCaptures::class);
        $captures->expects(self::once())->method('reportedWithin')->with(3 * 86400, 50)->willReturn([5, 9]);

        $rows = [5 => $this->createMock(OrderManagementStatus::class), 9 => $this->createMock(OrderManagementStatus::class)];
        $repository = $this->createMock(OrderManagementStatusRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(fn ($id) => $rows[$id]);

        $reconciled = [];
        $reconciler = $this->createMock(ShipmentCaptureReconciler::class);
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

        (new ReconcileShipmentCaptures($captures, $repository, $reconciler, $logManager))->execute();

        self::assertSame([5, 9], $reconciled);
    }
}
