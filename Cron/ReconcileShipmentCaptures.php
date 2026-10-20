<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Cron;

use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\OrderManagementStatus\ShipmentCaptureReconciler;
use Qliro\QliroOne\Model\ResourceModel\OrderManagementStatus\UnconfirmedShipmentCaptures;

/**
 * Applies the shipment captures Qliro confirmed before the shipment was saved, see etc/crontab.xml
 */
class ReconcileShipmentCaptures
{
    private const WINDOW_SECONDS = 3 * 86400;

    private const BATCH = 50;

    /**
     * @param UnconfirmedShipmentCaptures $captures
     * @param OrderManagementStatusRepositoryInterface $statusRepository
     * @param ShipmentCaptureReconciler $reconciler
     * @param LogManager $logManager
     */
    public function __construct(
        private readonly UnconfirmedShipmentCaptures $captures,
        private readonly OrderManagementStatusRepositoryInterface $statusRepository,
        private readonly ShipmentCaptureReconciler $reconciler,
        private readonly LogManager $logManager
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->captures->reportedWithin(self::WINDOW_SECONDS, self::BATCH) as $id) {
            // A reference left by the previous capture would tag this one's log lines
            $this->logManager->setMerchantReference(null);

            try {
                if (!$this->reconciler->reconcile($this->statusRepository->get($id))) {
                    $this->logManager->info(
                        'Shipment capture not applied: Qliro has not settled it, which the next run retries, '
                        . 'or handling it failed, which qliroone:capture:reconcile retries',
                        ['extra' => ['om_status_id' => $id]]
                    );
                }
            } catch (\Throwable $exception) {
                $this->logManager->warning(
                    'Shipment capture reconciliation failed, the next run retries',
                    ['extra' => ['om_status_id' => $id, 'error' => $exception->getMessage()]]
                );
            }
        }
    }
}
