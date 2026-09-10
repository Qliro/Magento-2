<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Model\ResourceModel\Db\Context;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Logger\ConnectionProvider;
use Qliro\QliroOne\Model\ResourceModel\LogRecord;

/**
 * @see \Qliro\QliroOne\Model\ResourceModel\LogRecord
 */
class LogRecordTest extends TestCase
{
    /**
     * Each statement takes one batch at most, and a full batch means there may be more, so the
     * loop goes on until a short one comes back. One statement over the whole backlog would hold
     * the table for as long as it takes.
     */
    public function testDeletesInBatchesUntilAShortBatchComesBack(): void
    {
        $connection = $this->connection([3, 3, 1]);
        $connection->expects(self::exactly(3))
            ->method('query')
            ->with(
                'DELETE FROM `prefix_qliroone_log` WHERE `date` < ? ORDER BY `date`, `id` LIMIT 3',
                ['2026-08-10 03:30:00']
            );

        $deleted = $this->buildLogRecord($connection)->deleteOlderThan('2026-08-10 03:30:00', 3);

        self::assertSame(7, $deleted);
    }

    /**
     * A backlog bigger than one run is left to the next one: batching bounds a statement, the cap
     * bounds the run, so the nightly job cannot hold a cron worker for hours.
     */
    public function testStopsAfterTheBatchCapAndLeavesTheRestToTheNextRun(): void
    {
        $connection = $this->connection([3, 3, 3, 3]);
        $connection->expects(self::exactly(2))->method('query');

        $deleted = $this->buildLogRecord($connection)->deleteOlderThan('2026-08-10 03:30:00', 3, 2);

        self::assertSame(6, $deleted);
    }

    /**
     * Nothing older than the cut off costs one statement and no more.
     */
    public function testStopsAfterOneStatementWhenThereIsNothingToDelete(): void
    {
        $connection = $this->connection([0]);
        $connection->expects(self::once())->method('query');

        self::assertSame(0, $this->buildLogRecord($connection)->deleteOlderThan('2026-08-10 03:30:00', 5000));
    }

    /**
     * Whether a backlog remains is a lookup on the indexed column, not a count.
     *
     * @dataProvider remainingRowsProvider
     */
    public function testAsksWhetherAnythingOlderIsStillThere(mixed $found, bool $expected): void
    {
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $n): string => '`' . $n . '`');

        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $select->expects(self::once())->method('limit')->with(1)->willReturnSelf();

        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->with($select)->willReturn($found);

        self::assertSame(
            $expected,
            $this->buildLogRecord($connection)->hasRowsOlderThan('2026-08-10 03:30:00')
        );
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function remainingRowsProvider(): array
    {
        return [
            'a row is still there' => ['1', true],
            'nothing older is left' => [false, false],
        ];
    }

    /**
     * @param int[] $rowCounts
     * @return AdapterInterface&MockObject
     */
    private function connection(array $rowCounts): AdapterInterface&MockObject
    {
        $statements = [];

        foreach ($rowCounts as $rowCount) {
            $statement = $this->createMock(\Zend_Db_Statement_Interface::class);
            $statement->method('rowCount')->willReturn($rowCount);
            $statements[] = $statement;
        }

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('quoteIdentifier')
            ->willReturnCallback(static fn(string $name): string => '`' . $name . '`');
        $connection->method('query')->willReturnOnConsecutiveCalls(...$statements);

        return $connection;
    }

    private function buildLogRecord(AdapterInterface $connection): LogRecord
    {
        $resources = $this->createMock(ResourceConnection::class);
        $resources->method('getConnection')->willReturn($connection);
        $resources->method('getTableName')->with('qliroone_log')->willReturn('prefix_qliroone_log');

        $context = $this->createMock(Context::class);
        $context->method('getResources')->willReturn($resources);

        return new LogRecord($context, $this->createMock(ConnectionProvider::class));
    }
}
