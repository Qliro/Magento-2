<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\ResourceModel\OrderManagementStatus;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Select;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterface as Status;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusInterface as QliroStatus;
use Qliro\QliroOne\Model\OrderManagementStatus\ShipmentCaptureReconciler;
use Qliro\QliroOne\Model\ResourceModel\OrderManagementStatus as StatusResource;

/**
 * Shipment captures no status from Qliro was applied to: still Created, with no handled or failed
 * row of a later status for the same transaction
 */
class UnconfirmedShipmentCaptures
{
    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Ids of the captures a Qliro callback was received for but not applied to, newest first,
     * so a new shipment is not held up behind older captures that keep failing
     *
     * @param int $seconds Callbacks received this long ago at most
     * @param int $limit
     * @return int[]
     */
    public function reportedWithin(int $seconds, int $limit): array
    {
        // Read from the recent callbacks by the date index, not from every capture ever made
        $select = $this->select(true)
            ->join(
                ['orphan' => $this->table()],
                'orphan.transaction_id = capture.transaction_id',
                []
            )
            // No record yet, or one skipped because another handling held the lock
            ->where(
                'orphan.record_type IS NULL OR orphan.notification_status = ?',
                Status::NOTIFICATION_STATUS_SKIPPED
            )
            ->where('orphan.transaction_status <> ?', QliroStatus::STATUS_CREATED)
            ->where('orphan.date >= NOW() - INTERVAL ? SECOND', $seconds)
            ->distinct()
            ->reset(Select::ORDER)
            ->order('capture.id DESC')
            ->limit($limit);

        return array_map('intval', $this->resource->getConnection()->fetchCol($select));
    }

    /**
     * Ids of the captures requested in a period, or of given orders, a failed handling included
     *
     * @param string|null $from
     * @param string|null $to
     * @param string[] $incrementIds
     * @param int $afterId Paging cursor, the last id of the previous page
     * @param int $limit
     * @return int[]
     */
    public function find(?string $from, ?string $to, array $incrementIds, int $afterId, int $limit): array
    {
        $select = $this->select(false)
            ->where('capture.id > ?', $afterId)
            ->limit($limit);

        if ($from !== null) {
            $select->where('capture.date >= ?', $from);
        }

        if ($to !== null) {
            $select->where('capture.date < ?', $to);
        }

        if ($incrementIds !== []) {
            $select->join(
                ['shipment' => $this->resource->getTableName('sales_shipment')],
                'shipment.entity_id = capture.record_id',
                []
            )->join(
                ['sales_order' => $this->resource->getTableName('sales_order')],
                'sales_order.entity_id = shipment.order_id',
                []
            )->where('sales_order.increment_id IN (?)', $incrementIds);
        }

        return array_map('intval', $this->resource->getConnection()->fetchCol($select));
    }

    /**
     * @param bool $failedCounts Whether a failed handling counts as applied. The cron does not retry
     * one, it would add a history comment on every run, the command does
     * @return Select
     */
    private function select(bool $failedCounts): Select
    {
        $connection = $this->resource->getConnection();
        $applied = $connection->select()
            ->from(['applied' => $this->table()], [new \Zend_Db_Expr('1')])
            ->where('applied.transaction_id = capture.transaction_id')
            ->where('applied.record_type IS NOT NULL')
            ->where('applied.transaction_status IN (?)', ShipmentCaptureReconciler::SETTLED)
            ->where(
                'applied.notification_status IN (?)',
                $failedCounts
                    ? [Status::NOTIFICATION_STATUS_DONE, Status::NOTIFICATION_STATUS_NEW, Status::NOTIFICATION_STATUS_ERROR]
                    : [Status::NOTIFICATION_STATUS_DONE]
            );

        return $connection->select()
            ->from(['capture' => $this->table()], ['id'])
            ->where('capture.record_type = ?', Status::RECORD_TYPE_SHIPMENT)
            ->where('capture.transaction_status = ?', QliroStatus::STATUS_CREATED)
            ->where('NOT EXISTS (?)', new \Zend_Db_Expr((string)$applied))
            ->order('capture.id ASC');
    }

    /**
     * @return string
     */
    private function table(): string
    {
        return $this->resource->getTableName(StatusResource::TABLE_OM_STATUS);
    }
}
