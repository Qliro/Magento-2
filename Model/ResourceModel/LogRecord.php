<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\ResourceModel;

use Qliro\QliroOne\Model\Logger\ConnectionProvider;
use Qliro\QliroOne\Model\LogRecord as LogRecordModel;
use Magento\Framework\Model\ResourceModel\Db\Context;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Qliro\QliroOne\Api\Data\LogRecordInterface;

class LogRecord extends AbstractDb
{
    const TABLE_LOG = 'qliroone_log';
    const RECENT_EVENT = 60;    // when patching merchant reference, look this many seconds back for recent logging
    const PRUNE_BATCH_SIZE = 5000;
    const PRUNE_MAX_BATCHES = 200;

    /**
     * @var \Qliro\QliroOne\Model\Logger\ConnectionProvider
     */
    private $connectionProvider;

    /**
     * LogRecord constructor.
     *
     * @param Context $context
     * @param \Qliro\QliroOne\Model\Logger\ConnectionProvider $connectionProvider
     */
    public function __construct(
        Context $context,
        ConnectionProvider $connectionProvider
    ) {
        parent::__construct($context);
        $this->connectionProvider = $connectionProvider;
    }

    protected function _construct()
    {
        $this->_init(self::TABLE_LOG, LogRecordModel::FIELD_ID);
    }

    /**
     * When we have a merchantReference, we should patch any recent logging to ensure that the reference is present
     * on all log lines.
     *
     * @param string $merchantReference
     */
    public function patchMerchantReference($merchantReference)
    {
        /** @var \Magento\Framework\DB\Adapter\AdapterInterface $connection */
        $connection = $this->connectionProvider->getConnection();

        $where = [
            sprintf("%s = ?", LogRecordInterface::FIELD_PROCESS_ID) => \getmypid(),
            sprintf("%s = ?", LogRecordInterface::FIELD_REFERENCE) => '',
            sprintf("%s >= NOW() - ?", LogRecordInterface::FIELD_DATE) => self::RECENT_EVENT
        ];
        try {
            $rows = $connection->update($this->getTable(
                self::TABLE_LOG),
                [LogRecordInterface::FIELD_REFERENCE => $merchantReference],
                $where);
        } catch (\Exception $e) {
        }
    }

    /**
     * Delete the rows logged before the given moment, one batch per statement so a backlog never holds the table
     *
     * @param string $before A date in the format the rows are stored in, `Y-m-d H:i:s`
     * @param int $batchSize
     * @param int $maxBatches Caps the whole run, the rest is left to the next one
     * @return int Rows deleted
     */
    public function deleteOlderThan(
        string $before,
        int $batchSize = self::PRUNE_BATCH_SIZE,
        int $maxBatches = self::PRUNE_MAX_BATCHES
    ): int {
        $connection = $this->getConnection();
        $batchSize = max(1, $batchSize);
        $maxBatches = max(1, $maxBatches);
        $deleted = 0;
        $batches = 0;

        do {
            // Ordered by the indexed column the WHERE uses, the id makes the batch deterministic for a replica
            $rows = $connection->query(
                sprintf(
                    'DELETE FROM %s WHERE %s < ? ORDER BY %s, %s LIMIT %d',
                    $connection->quoteIdentifier($this->getMainTable()),
                    $connection->quoteIdentifier(LogRecordInterface::FIELD_DATE),
                    $connection->quoteIdentifier(LogRecordInterface::FIELD_DATE),
                    $connection->quoteIdentifier(LogRecordModel::FIELD_ID),
                    $batchSize
                ),
                [$before]
            )->rowCount();

            $deleted += $rows;
            $batches++;
        } while ($rows >= $batchSize && $batches < $maxBatches);

        return $deleted;
    }

    /**
     * Whether anything logged before the given moment is still there, asked in constant time
     *
     * @param string $before A date in the format the rows are stored in, `Y-m-d H:i:s`
     * @return bool
     */
    public function hasRowsOlderThan(string $before): bool
    {
        $connection = $this->getConnection();

        return (bool)$connection->fetchOne(
            $connection->select()
                ->from($this->getMainTable(), new \Zend_Db_Expr('1'))
                ->where(
                    sprintf('%s < ?', $connection->quoteIdentifier(LogRecordInterface::FIELD_DATE)),
                    $before
                )
                ->limit(1)
        );
    }
}
