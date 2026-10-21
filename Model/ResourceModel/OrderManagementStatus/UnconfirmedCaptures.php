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
use Qliro\QliroOne\Model\OrderManagementStatus\CaptureReconciler;
use Qliro\QliroOne\Model\ResourceModel\OrderManagementStatus as StatusResource;

/**
 * Captures no status from Qliro was applied to: still Created, with no handled or failed row of a
 * later status for the same transaction
 *
 * A capture on shipment and a capture on invoice alike. Both are requested inside a save Magento
 * can still roll back, a shipment always and an invoice through the REST API, and Qliro's callback
 * can arrive before that save commits.
 */
class UnconfirmedCaptures
{
    public const RECORD_TYPES = [Status::RECORD_TYPE_SHIPMENT, Status::RECORD_TYPE_PAYMENT];

    /**
     * A Success whose handling failed is tried this many times, it adds nothing to the order until
     * it invoices. A failed Error or Cancelled is not, it holds the order and comments on it.
     */
    public const SUCCESS_ATTEMPTS = 3;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Ids of the captures requested in the last days and still unconfirmed, newest first, so a new
     * capture is not held up behind older ones that keep failing
     *
     * A capture is due once a callback for it was stored without being applied, or once the
     * callback had its time to arrive: one that never came, or failed before it was stored, leaves
     * no row of its own to find.
     *
     * @param int $seconds Captures requested this long ago at most
     * @param int $graceSeconds Captures with no stored callback are left this long to the callback
     * @param int $limit
     * @return int[]
     */
    public function due(int $seconds, int $graceSeconds, int $limit): array
    {
        $connection = $this->resource->getConnection();
        $orphan = $connection->select()
            ->from(['orphan' => $this->table()], [new \Zend_Db_Expr('1')])
            ->where('orphan.transaction_id = capture.transaction_id')
            // No record yet, or one skipped because another handling held the lock
            ->where('orphan.record_type IS NULL OR orphan.notification_status = ?', Status::NOTIFICATION_STATUS_SKIPPED)
            ->where('orphan.transaction_status <> ?', QliroStatus::STATUS_CREATED);

        // Read from the recent captures by the date index, not from every capture ever made
        $select = $this->select(true)
            ->where('capture.date >= NOW() - INTERVAL ? SECOND', $seconds)
            ->where(
                sprintf('EXISTS (%s) OR %s', $orphan, $connection->quoteInto('capture.date <= NOW() - INTERVAL ? SECOND', $graceSeconds))
            )
            ->reset(Select::ORDER)
            ->order('capture.id DESC')
            ->limit($limit);

        return array_map('intval', $connection->fetchCol($select));
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
            // Through the link, which every record type shares, rather than through the record
            $select->join(
                ['link' => $this->resource->getTableName('qliroone_link')],
                'link.qliro_order_id = capture.qliro_order_id',
                []
            )->join(
                ['sales_order' => $this->resource->getTableName('sales_order')],
                'sales_order.entity_id = link.order_id',
                []
            )->where('sales_order.increment_id IN (?)', $incrementIds)
                ->distinct();
        }

        return array_map('intval', $this->resource->getConnection()->fetchCol($select));
    }

    /**
     * @param bool $failedCounts Whether a failed handling counts as applied. The cron retries a
     * failed Success a few times and leaves a failed Error or Cancelled alone, see SUCCESS_ATTEMPTS.
     * The command retries every one
     * @return Select
     */
    private function select(bool $failedCounts): Select
    {
        $connection = $this->resource->getConnection();
        $applied = $connection->select()
            ->from(['applied' => $this->table()], [new \Zend_Db_Expr('1')])
            ->where('applied.transaction_id = capture.transaction_id')
            ->where('applied.record_type IS NOT NULL')
            ->where('applied.transaction_status IN (?)', CaptureReconciler::SETTLED);

        $select = $connection->select()
            ->from(['capture' => $this->table()], ['id'])
            ->where('capture.record_type IN (?)', self::RECORD_TYPES)
            ->where('capture.transaction_status = ?', QliroStatus::STATUS_CREATED)
            ->order('capture.id ASC');

        if (!$failedCounts) {
            $applied->where('applied.notification_status = ?', Status::NOTIFICATION_STATUS_DONE);

            return $select->where('NOT EXISTS (?)', new \Zend_Db_Expr((string)$applied));
        }

        $failed = [Status::NOTIFICATION_STATUS_NEW, Status::NOTIFICATION_STATUS_ERROR];
        $applied->where(sprintf(
            '%s OR (%s AND %s)',
            $connection->quoteInto('applied.notification_status = ?', Status::NOTIFICATION_STATUS_DONE),
            $connection->quoteInto('applied.notification_status IN (?)', $failed),
            $connection->quoteInto('applied.transaction_status <> ?', QliroStatus::STATUS_SUCCESS)
        ));

        $failedSuccess = $connection->select()
            ->from(['attempt' => $this->table()], [new \Zend_Db_Expr('COUNT(*)')])
            ->where('attempt.transaction_id = capture.transaction_id')
            ->where('attempt.record_type IS NOT NULL')
            ->where('attempt.transaction_status = ?', QliroStatus::STATUS_SUCCESS)
            ->where('attempt.notification_status IN (?)', $failed);

        return $select
            ->where('NOT EXISTS (?)', new \Zend_Db_Expr((string)$applied))
            ->where('(?) < ' . self::SUCCESS_ATTEMPTS, new \Zend_Db_Expr((string)$failedSuccess));
    }

    /**
     * @return string
     */
    private function table(): string
    {
        return $this->resource->getTableName(StatusResource::TABLE_OM_STATUS);
    }
}
