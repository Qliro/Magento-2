<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Console;

use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Console\PruneLogCommand;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Service\Log\Pruner;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @see \Qliro\QliroOne\Console\PruneLogCommand
 */
class PruneLogCommandTest extends TestCase
{
    /**
     * Without an option the pruner resolves the configured window, and the command reports what it did.
     */
    public function testPrunesWithTheConfiguredRetention(): void
    {
        $pruner = $this->createMock(Pruner::class);
        $pruner->expects(self::once())->method('resolveRetentionDays')->with(null)->willReturn(30);
        $pruner->expects(self::once())->method('prune')->with(30)->willReturn(120);

        $tester = $this->runCommand($pruner, []);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Deleted 120 log rows older than 30 days', $tester->getDisplay());
    }

    /**
     * --days is handed to the pruner as the window for this run, the setting is untouched.
     */
    public function testPrunesWithTheDaysGiven(): void
    {
        $pruner = $this->createMock(Pruner::class);
        $pruner->expects(self::once())->method('resolveRetentionDays')->with(7)->willReturn(7);
        $pruner->expects(self::once())->method('prune')->with(7)->willReturn(3);

        $tester = $this->runCommand($pruner, ['--days' => '7']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Deleted 3 log rows older than 7 days', $tester->getDisplay());
    }

    /**
     * A window of 0 is still handed to the pruner, which keeps everything, and is reported as kept.
     */
    public function testReportsEveryRowKeptWhenTheRetentionIsZero(): void
    {
        $pruner = $this->createMock(Pruner::class);
        $pruner->method('resolveRetentionDays')->willReturn(0);
        $pruner->expects(self::once())->method('prune')->with(0)->willReturn(0);

        $tester = $this->runCommand($pruner, ['--days' => '0']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('every log row is kept', $tester->getDisplay());
    }

    /**
     * A run that stopped at the batch cap tells the operator to run it again.
     */
    public function testSaysWhenMoreRowsRemain(): void
    {
        $pruner = $this->createMock(Pruner::class);
        $pruner->method('resolveRetentionDays')->willReturn(30);
        $pruner->method('prune')->willReturn(1000000);
        $pruner->method('hasBacklog')->with(30)->willReturn(true);

        $tester = $this->runCommand($pruner, []);

        self::assertStringContainsString('run this again to continue', $tester->getDisplay());
    }

    /**
     * A value the field itself would refuse is refused here too, and the pruner is asked nothing
     * at all, so the CLI and the admin form cannot answer differently.
     *
     * @dataProvider invalidDaysProvider
     */
    public function testRefusesADaysValueTheFieldWouldRefuse(string $days): void
    {
        $pruner = $this->createMock(Pruner::class);
        $pruner->expects(self::never())->method('resolveRetentionDays');
        $pruner->expects(self::never())->method('prune');

        $tester = $this->runCommand($pruner, ['--days' => $days]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--days must be a whole number of days between 0 and', $tester->getDisplay());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidDaysProvider(): array
    {
        return [
            'negative' => ['-1'],
            'fraction' => ['1.5'],
            'word' => ['week'],
            'empty' => [''],
            'beyond what a date can hold' => [(string)(Config::MAX_LOG_RETENTION_DAYS + 1)],
        ];
    }

    /**
     * @param Pruner $pruner
     * @param array<string, string> $input
     * @return CommandTester
     */
    private function runCommand(Pruner $pruner, array $input): CommandTester
    {
        $config = new Config(
            $this->createMock(\Magento\Payment\Model\Method\Adapter::class),
            $this->createMock(\Magento\Framework\App\Config\ScopeConfigInterface::class),
            $this->createMock(\Magento\Framework\Serialize\Serializer\Json::class),
            $this->createMock(\Magento\Directory\Helper\Data::class),
            $this->createMock(\Magento\Directory\Model\ResourceModel\Country\CollectionFactory::class)
        );

        $tester = new CommandTester(new PruneLogCommand($pruner, $config));
        $tester->execute($input);

        return $tester;
    }
}
