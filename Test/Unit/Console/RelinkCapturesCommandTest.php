<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Console;

use Magento\Framework\App\State;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Console\RelinkCapturesCommand;
use Qliro\QliroOne\Model\QliroOrder\Admin\InvoiceCaptureRelinker;
use Qliro\QliroOne\Model\ResourceModel\AdoptedCaptureInvoices;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @see \Qliro\QliroOne\Console\RelinkCapturesCommand
 */
class RelinkCapturesCommandTest extends TestCase
{
    private AdoptedCaptureInvoices&MockObject $invoices;
    private InvoiceCaptureRelinker&MockObject $relinker;

    protected function setUp(): void
    {
        $this->invoices = $this->createMock(AdoptedCaptureInvoices::class);
        $this->relinker = $this->createMock(InvoiceCaptureRelinker::class);
    }

    public function testADryRunIsPassedOnAndCounted(): void
    {
        $this->invoices->method('find')
            ->with('2026-07-01 00:00:00', '2026-10-16 00:00:00', ['000246718'], self::anything(), 100)
            ->willReturnOnConsecutiveCalls([7], []);
        $this->relinker->expects(self::once())->method('relink')->with(7, true)
            ->willReturn([$this->line(InvoiceCaptureRelinker::RELINKED, 331743203)]);

        $tester = $this->runCommand([
            '--from' => '2026-07-01',
            '--to' => '2026-10-16',
            '--order' => ['000246718'],
            '--dry-run' => true,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Invoice 000000009, transaction 324964039: relinked 331743203, 527', $tester->getDisplay());
        self::assertStringContainsString(
            'Would relink 1, captured amount to record 0, left on their capture 0, not settled at Qliro 0, to check by hand 0, failed 0',
            $tester->getDisplay()
        );
    }

    /**
     * Pages go on from the last order id, and an order that fails does not end the run
     */
    public function testPagesOnAndCountsEachOutcome(): void
    {
        $pages = [];
        $this->invoices->method('find')->willReturnCallback(function ($from, $to, $orders, $afterId) use (&$pages) {
            $pages[] = $afterId;
            return [0 => [3, 4], 4 => [5], 5 => []][$afterId];
        });
        $this->relinker->method('relink')->willReturnCallback(fn (int $orderId) => match ($orderId) {
            3 => [
                $this->line(InvoiceCaptureRelinker::RELINKED, 1),
                $this->line(InvoiceCaptureRelinker::CAPTURE, null),
                $this->line(InvoiceCaptureRelinker::TRACKED, 6),
            ],
            4 => throw new \RuntimeException('Qliro unreachable'),
            5 => [
                $this->line(InvoiceCaptureRelinker::MANUAL, null),
                $this->line(InvoiceCaptureRelinker::PENDING, 2),
                $this->line(InvoiceCaptureRelinker::FAILED, null),
            ],
        });

        $tester = $this->runCommand(['--from' => '2000-01-01']);

        self::assertSame([0, 4, 5], $pages);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Order 4: Qliro unreachable', $tester->getDisplay());
        self::assertStringContainsString(
            'Relinked 1, captured amount recorded 1, left on their capture 1, not settled at Qliro 1, to check by hand 1, failed 2',
            $tester->getDisplay()
        );
    }

    public function testRefusesARunWithNeitherAPeriodNorAnOrder(): void
    {
        $this->invoices->expects(self::never())->method('find');

        $tester = $this->runCommand(['--to' => '2026-10-16']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--from=2000-01-01', $tester->getDisplay());
    }

    public function testRefusesADateItCannotRead(): void
    {
        $this->invoices->expects(self::never())->method('find');

        self::assertSame(1, $this->runCommand(['--from' => '16.10.2026'])->getStatusCode());
    }

    private function line(string $outcome, ?int $to): array
    {
        return ['invoice' => '000000009', 'from' => '324964039', 'to' => $to, 'outcome' => $outcome, 'note' => '527'];
    }

    private function runCommand(array $input): CommandTester
    {
        $tester = new CommandTester(
            new RelinkCapturesCommand($this->invoices, $this->relinker, $this->createMock(State::class))
        );
        $tester->execute($input);

        return $tester;
    }
}
