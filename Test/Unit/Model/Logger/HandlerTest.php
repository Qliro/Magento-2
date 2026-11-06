<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Logger;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\DeadlockException;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Logger\ConnectionProvider;
use Qliro\QliroOne\Model\Logger\Formatter;
use Qliro\QliroOne\Model\Logger\Handler;

/**
 * @see \Qliro\QliroOne\Model\Logger\Handler
 */
class HandlerTest extends TestCase
{
    private AdapterInterface $connection;
    private Handler $handler;

    /** @var array<int, array<string, mixed>> */
    private array $inserted = [];

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('getTableName')->willReturnArgument(0);

        $connectionProvider = $this->createMock(ConnectionProvider::class);
        $connectionProvider->method('getConnection')->willReturn($this->connection);

        $this->handler = new Handler(new Formatter(), $connectionProvider);
    }

    public function testRetryAfterDeadlockInsertsTheOriginalRow(): void
    {
        $this->insertsFailing(new DeadlockException('Deadlock found'));

        $this->handler->handle($this->record());

        self::assertCount(2, $this->inserted);
        self::assertSame($this->inserted[0], $this->inserted[1]);

        $row = $this->inserted[1];
        self::assertSame('MARK: Capture sent', $row['message']);
        self::assertSame('INFO', $row['level']);
        self::assertSame('qliroone-42', $row['reference']);
        self::assertSame('capture', $row['tags']);
        self::assertSame('1234', $row['process_id']);
        self::assertSame(['amount' => 100], json_decode($row['extra'], true));
    }

    public function testRetryOfAnArrayRecordInsertsTheOriginalRow(): void
    {
        $this->insertsFailing(new DeadlockException('Deadlock found'));

        $record = $this->record()->toArray();
        $record['formatted'] = (new Formatter())->format($record);
        $write = new \ReflectionMethod(Handler::class, 'write');
        $write->invoke($this->handler, $record);

        self::assertCount(2, $this->inserted);
        self::assertSame('MARK: Capture sent', $this->inserted[1]['message']);
        self::assertSame('INFO', $this->inserted[1]['level']);
        self::assertSame('qliroone-42', $this->inserted[1]['reference']);
    }

    public function testGivesUpQuietlyAfterTheLastDeadlock(): void
    {
        $this->insertsFailing(
            new DeadlockException('Deadlock found'),
            new DeadlockException('Deadlock found'),
            new DeadlockException('Deadlock found')
        );

        $this->handler->handle($this->record());

        self::assertCount(3, $this->inserted);
    }

    public function testOtherFailureIsNotRetried(): void
    {
        $this->insertsFailing(new \RuntimeException('Table is read only'));

        $this->handler->handle($this->record());

        self::assertCount(1, $this->inserted);
    }

    private function insertsFailing(\Throwable ...$failures): void
    {
        $this->connection->method('insert')->willReturnCallback(
            function (string $table, array $row) use (&$failures): int {
                $this->inserted[] = $row;
                $failure = array_shift($failures);
                if ($failure !== null) {
                    throw $failure;
                }

                return 1;
            }
        );
    }

    private function record(): LogRecord
    {
        return new LogRecord(
            new \DateTimeImmutable(),
            'qliroone',
            Level::Info,
            'Capture sent',
            [
                'mark' => 'mark',
                'reference' => 'qliroone-42',
                'tags' => 'capture',
                'process_id' => '1234',
                'extra' => ['amount' => 100],
            ]
        );
    }
}
