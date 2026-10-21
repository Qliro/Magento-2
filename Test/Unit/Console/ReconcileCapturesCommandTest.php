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
use Qliro\QliroOne\Api\Data\AdminOrderPaymentTransactionInterface;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Console\ReconcileCapturesCommand;
use Qliro\QliroOne\Model\OrderManagementStatus;
use Qliro\QliroOne\Model\OrderManagementStatus\CaptureReconciler;
use Qliro\QliroOne\Model\ResourceModel\OrderManagementStatus\UnconfirmedCaptures;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @see \Qliro\QliroOne\Console\ReconcileCapturesCommand
 */
class ReconcileCapturesCommandTest extends TestCase
{
    private UnconfirmedCaptures&MockObject $captures;
    private CaptureReconciler&MockObject $reconciler;

    protected function setUp(): void
    {
        $this->captures = $this->createMock(UnconfirmedCaptures::class);
        $this->reconciler = $this->createMock(CaptureReconciler::class);
    }

    /**
     * A dry run asks Qliro and lists, it applies nothing
     */
    public function testDryRunAppliesNothing(): void
    {
        $this->captures->method('find')
            ->with('2026-03-11 00:00:00', '2026-10-17 00:00:00', ['000246718'], self::anything(), 100)
            ->willReturnOnConsecutiveCalls([4], []);
        $this->reconciler->method('findSettled')->willReturn($this->transaction());
        $this->reconciler->expects(self::never())->method('apply');

        $tester = $this->runCommand([
            '--from' => '2026-03-11',
            '--to' => '2026-10-17',
            '--order' => ['000246718'],
            '--dry-run' => true,
        ]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Would apply 1, not settled at Qliro 0, to check by hand 0, failed 0', $tester->getDisplay());
    }

    /**
     * Pages go on from the last id, a capture Qliro has not settled is counted, a failed one is
     * left to a person, and one that could not be applied makes the run fail
     */
    public function testAppliesPageByPageAndReportsWhatFailed(): void
    {
        $pages = [];
        $this->captures->method('find')->willReturnCallback(function ($from, $to, $orders, $afterId) use (&$pages) {
            $pages[] = $afterId;
            return [0 => [1, 2], 2 => [3, 4], 4 => []][$afterId];
        });
        $this->reconciler->method('findSettled')->willReturnOnConsecutiveCalls(
            $this->transaction(),
            null,
            $this->transaction('Error'),
            $this->transaction()
        );
        $this->reconciler->expects(self::exactly(2))->method('apply')->willReturnOnConsecutiveCalls(true, false);

        $tester = $this->runCommand(['--from' => '2000-01-01']);

        self::assertSame([0, 2, 4], $pages);
        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString(
            'Applied 1, not settled at Qliro 1, to check by hand 1, failed 1',
            $tester->getDisplay()
        );
    }

    /**
     * A row gone between the page and the read is counted as failed, the run goes on
     */
    public function testARowThatCannotBeReadDoesNotEndTheRun(): void
    {
        $this->captures->method('find')->willReturnOnConsecutiveCalls([404, 5], []);
        $this->reconciler->method('findSettled')->willReturn($this->transaction());
        $this->reconciler->method('apply')->willReturn(true);

        $tester = $this->runCommand(['--order' => ['000246718']]);

        self::assertStringContainsString('Applied 1, not settled at Qliro 0, to check by hand 0, failed 1', $tester->getDisplay());
    }

    /**
     * Every capture is a call to Qliro, so a run over the whole history has to be asked for
     */
    public function testRefusesARunWithNeitherAPeriodNorAnOrder(): void
    {
        $this->captures->expects(self::never())->method('find');

        $tester = $this->runCommand(['--to' => '2026-10-17']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--from=2000-01-01', $tester->getDisplay());
    }

    public function testRefusesADateItCannotRead(): void
    {
        $this->captures->expects(self::never())->method('find');

        $tester = $this->runCommand(['--from' => '11.03.2026']);

        self::assertSame(1, $tester->getStatusCode());
    }

    /**
     * @param array $input
     * @return CommandTester
     */
    private function runCommand(array $input): CommandTester
    {
        $repository = $this->createMock(OrderManagementStatusRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(function ($id) {
            if ($id === 404) {
                throw new \Magento\Framework\Exception\NoSuchEntityException(__('gone'));
            }

            $row = $this->getMockBuilder(OrderManagementStatus::class)
                ->disableOriginalConstructor()
                ->onlyMethods([])
                ->getMock();
            $row->setData(['id' => $id, 'record_id' => 10 + $id, 'transaction_id' => 100 + $id]);

            return $row;
        });

        $tester = new CommandTester(new ReconcileCapturesCommand(
            $this->captures,
            $repository,
            $this->reconciler,
            $this->createMock(State::class),
            $this->createMock(\Qliro\QliroOne\Model\Logger\Manager::class)
        ));
        $tester->execute($input);

        return $tester;
    }

    /**
     * @param string $status
     * @return AdminOrderPaymentTransactionInterface
     */
    private function transaction(string $status = 'Success'): AdminOrderPaymentTransactionInterface
    {
        $transaction = $this->createMock(AdminOrderPaymentTransactionInterface::class);
        $transaction->method('getStatus')->willReturn($status);
        $transaction->method('getAmount')->willReturn(793.0);

        return $transaction;
    }
}
