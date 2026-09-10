<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Service\Log;

use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\ResourceModel\LogRecord;
use Qliro\QliroOne\Service\Log\Pruner;

/**
 * @see \Qliro\QliroOne\Service\Log\Pruner
 */
class PrunerTest extends TestCase
{
    private const CUT_OFF = '2026-08-09 03:30:00';

    /**
     * The cut off is the configured retention counted back from now, in UTC like the stored rows.
     */
    public function testDeletesTheRowsOlderThanTheConfiguredRetention(): void
    {
        $logRecord = $this->createMock(LogRecord::class);
        $logRecord->expects(self::once())->method('deleteOlderThan')->with(self::CUT_OFF)->willReturn(12);

        $pruner = $this->buildPruner(30, $logRecord, $this->dateTime(30));

        self::assertSame(12, $pruner->prune());
    }

    /**
     * The console command can run with a window of its own.
     */
    public function testTakesTheRetentionItIsGiven(): void
    {
        $logRecord = $this->createMock(LogRecord::class);
        $logRecord->expects(self::once())->method('deleteOlderThan')->with(self::CUT_OFF)->willReturn(3);

        self::assertSame(3, $this->buildPruner(30, $logRecord, $this->dateTime(1))->prune(1));
    }

    /**
     * A retention of 0 keeps every row, configured or given, and asks the database nothing.
     */
    public function testKeepsEveryRowWhenTheRetentionIsZero(): void
    {
        $logRecord = $this->createMock(LogRecord::class);
        $logRecord->expects(self::never())->method('deleteOlderThan');

        self::assertSame(0, $this->buildPruner(0, $logRecord)->prune());
        self::assertSame(0, $this->buildPruner(30, $logRecord)->prune(0));
    }

    /**
     * A run that deleted something leaves one row saying so, a run that deleted nothing leaves none.
     */
    public function testLogsARunThatDeletedSomethingAndStaysQuietOtherwise(): void
    {
        $logRecord = $this->createMock(LogRecord::class);
        $logRecord->method('deleteOlderThan')->willReturnOnConsecutiveCalls(5, 0);

        $logManager = $this->createMock(LogManager::class);
        $logManager->expects(self::once())->method('info');

        $pruner = $this->buildPruner(30, $logRecord, $this->dateTime(30, 2), $logManager);
        $pruner->prune();
        $pruner->prune();
    }

    /**
     * A run that stopped at the batch cap says so, otherwise a backlog looks like a pruned table.
     */
    public function testSaysWhenTheBatchCapWasReached(): void
    {
        $logRecord = $this->createMock(LogRecord::class);
        $logRecord->method('deleteOlderThan')->willReturn(1000000);
        $logRecord->method('hasRowsOlderThan')->with(self::CUT_OFF)->willReturn(true);

        $logManager = $this->createMock(LogManager::class);
        $logManager->expects(self::once())
            ->method('info')
            ->with(self::stringContains('more remain'), self::anything());

        $this->buildPruner(30, $logRecord, $this->dateTime(30), $logManager)->prune();
    }

    /**
     * The console command asks the same question, and a window of 0 has no backlog by definition.
     *
     * @dataProvider backlogProvider
     */
    public function testAnswersWhetherABacklogRemains(int $retentionDays, bool $olderRowsExist, bool $expected): void
    {
        $logRecord = $this->createMock(LogRecord::class);
        $logRecord->method('hasRowsOlderThan')->willReturn($olderRowsExist);

        $dateTime = $retentionDays > 0 ? $this->dateTime($retentionDays) : null;

        self::assertSame($expected, $this->buildPruner(30, $logRecord, $dateTime)->hasBacklog($retentionDays));
    }

    /**
     * @return array<string, array{int, bool, bool}>
     */
    public static function backlogProvider(): array
    {
        return [
            'rows are still behind the window' => [30, true, true],
            'the window is worked off' => [30, false, false],
            'keeping everything cannot have a backlog' => [0, true, false],
        ];
    }

    /**
     * The window is the one given, else the configured one, and never more than a date can hold:
     * a typo like 3650000 would otherwise build a cut off the database refuses, every night.
     *
     * @dataProvider retentionProvider
     */
    public function testResolvesTheWindowARunIsHeldTo(?int $given, int $configured, int $expected): void
    {
        $pruner = $this->buildPruner($configured, $this->createMock(LogRecord::class));

        self::assertSame($expected, $pruner->resolveRetentionDays($given));
    }

    /**
     * @return array<string, array{int|null, int, int}>
     */
    public static function retentionProvider(): array
    {
        return [
            'the configured window' => [null, 30, 30],
            'a window of its own' => [7, 30, 7],
            'zero given keeps everything' => [0, 30, 0],
            'a value beyond what a date can hold is capped' => [PHP_INT_MAX, 30, Config::MAX_LOG_RETENTION_DAYS],
            'a negative value keeps everything' => [-5, 30, 0],
        ];
    }

    /**
     * The cap holds on the way to the database too, not only on the number reported.
     */
    public function testPrunesWithTheCappedWindow(): void
    {
        $logRecord = $this->createMock(LogRecord::class);
        $logRecord->expects(self::once())->method('deleteOlderThan')->with(self::CUT_OFF)->willReturn(0);

        $pruner = $this->buildPruner(30, $logRecord, $this->dateTime(Config::MAX_LOG_RETENTION_DAYS));

        $pruner->prune(PHP_INT_MAX);
    }

    /**
     * A helper that answers with the cut off only when asked for the given number of days back
     *
     * @param int $days
     * @param int $times
     * @return DateTime
     */
    private function dateTime(int $days, int $times = 1): DateTime
    {
        $dateTime = $this->createMock(DateTime::class);
        $dateTime->expects(self::exactly($times))
            ->method('gmtDate')
            ->with(
                null,
                self::callback(static fn(int $timestamp): bool => abs($timestamp - (time() - $days * 86400)) < 60)
            )
            ->willReturn(self::CUT_OFF);

        return $dateTime;
    }

    private function buildPruner(
        int $configuredDays,
        LogRecord $logRecord,
        ?DateTime $dateTime = null,
        ?LogManager $logManager = null
    ): Pruner {
        $config = $this->createMock(Config::class);
        $config->method('getLogRetentionDays')->willReturn($configuredDays);

        return new Pruner(
            $config,
            $logRecord,
            $logManager ?? $this->createMock(LogManager::class),
            $dateTime ?? $this->createMock(DateTime::class)
        );
    }
}
