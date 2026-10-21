<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Cron;

use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\OrderManagementStatus\CaptureReconciler;
use Qliro\QliroOne\Model\ResourceModel\OrderManagementStatus\UnconfirmedCaptures;

/**
 * Applies the captures Qliro confirmed before the shipment or invoice was saved, see etc/crontab.xml
 */
class ReconcileCaptures
{
    private const WINDOW_SECONDS = 3 * 86400;

    // Long past the second a callback takes, so a capture is not read from Qliro while its callback is on the way
    private const GRACE_SECONDS = 15 * 60;

    private const BATCH = 50;

    /**
     * @param UnconfirmedCaptures $captures
     * @param OrderManagementStatusRepositoryInterface $statusRepository
     * @param CaptureReconciler $reconciler
     * @param LogManager $logManager
     */
    public function __construct(
        private readonly UnconfirmedCaptures $captures,
        private readonly OrderManagementStatusRepositoryInterface $statusRepository,
        private readonly CaptureReconciler $reconciler,
        private readonly LogManager $logManager
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->captures->due(self::WINDOW_SECONDS, self::GRACE_SECONDS, self::BATCH) as $id) {
            // A reference left by the previous capture would tag this one's log lines
            $this->logManager->setMerchantReference(null);

            try {
                if (!$this->reconciler->reconcile($this->statusRepository->get($id))) {
                    $this->logManager->info(
                        'Capture not applied: Qliro has not settled it, or handling it failed, both of '
                        . 'which the next run retries, a failed Success up to '
                        . UnconfirmedCaptures::SUCCESS_ATTEMPTS . ' times and qliroone:capture:reconcile after that',
                        ['extra' => ['om_status_id' => $id]]
                    );
                }
            } catch (\Throwable $exception) {
                $this->logManager->warning(
                    'Capture reconciliation failed, the next run retries',
                    ['extra' => ['om_status_id' => $id, 'error' => $exception->getMessage()]]
                );
            }
        }
    }
}
