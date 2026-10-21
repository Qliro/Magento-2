<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Console;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusInterface as QliroStatus;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\OrderManagementStatus\CaptureReconciler;
use Qliro\QliroOne\Model\ResourceModel\OrderManagementStatus\UnconfirmedCaptures;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Applies what Qliro holds for captures that never got their status, older ones included
 */
class ReconcileCapturesCommand extends Command
{
    const COMMAND_RUN = 'qliroone:capture:reconcile';

    const OPTION_FROM = 'from';
    const OPTION_TO = 'to';
    const OPTION_ORDER = 'order';
    const OPTION_DRY_RUN = 'dry-run';

    private const PAGE = 100;

    /**
     * @param UnconfirmedCaptures $captures
     * @param OrderManagementStatusRepositoryInterface $statusRepository
     * @param CaptureReconciler $reconciler
     * @param State $appState
     * @param LogManager $logManager
     * @param string|null $name
     */
    public function __construct(
        private readonly UnconfirmedCaptures $captures,
        private readonly OrderManagementStatusRepositoryInterface $statusRepository,
        private readonly CaptureReconciler $reconciler,
        private readonly State $appState,
        private readonly LogManager $logManager,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * @inheritDoc
     */
    protected function configure()
    {
        $this->setName(self::COMMAND_RUN);
        $this->setDescription('Apply Qliro capture confirmations that were lost');
        $this->addOption(self::OPTION_FROM, null, InputOption::VALUE_REQUIRED, 'Captures requested on or after this UTC date, Y-m-d');
        $this->addOption(self::OPTION_TO, null, InputOption::VALUE_REQUIRED, 'Captures requested before this UTC date, Y-m-d');
        $this->addOption(
            self::OPTION_ORDER,
            'o',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Only this order increment id, can be repeated'
        );
        $this->addOption(self::OPTION_DRY_RUN, null, InputOption::VALUE_NONE, 'Ask Qliro and list, change nothing');

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $from = $this->date($input->getOption(self::OPTION_FROM));
        $to = $this->date($input->getOption(self::OPTION_TO));

        if ($from === false || $to === false) {
            $output->writeln('<error>--from and --to take a date as Y-m-d</error>');

            return 1;
        }

        $orders = $input->getOption(self::OPTION_ORDER) ?: [];

        // Every capture is a call to Qliro, so the whole history is asked for by name, not by default
        if ($from === null && $orders === []) {
            $output->writeln('<error>Give --from, or --order, a store\'s whole history is --from=2000-01-01</error>');

            return 1;
        }

        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Magento\Framework\Exception\LocalizedException $exception) {
            // Already set
        }

        $dryRun = (bool)$input->getOption(self::OPTION_DRY_RUN);
        $counts = ['applied' => 0, 'pending' => 0, 'manual' => 0, 'failed' => 0];
        $afterId = 0;

        while ($ids = $this->captures->find($from, $to, $orders, $afterId, self::PAGE)) {
            foreach ($ids as $id) {
                $afterId = $id;
                $outcome = $this->reconcileOne($id, $dryRun, $output);
                $counts[$outcome]++;
            }
        }

        $output->writeln(sprintf(
            '<info>%s %d, not settled at Qliro %d, to check by hand %d, failed %d</info>',
            $dryRun ? 'Would apply' : 'Applied',
            $counts['applied'],
            $counts['pending'],
            $counts['manual'],
            $counts['failed']
        ));

        return $counts['failed'] > 0 ? 1 : 0;
    }

    /**
     * @param int $id
     * @param bool $dryRun
     * @param OutputInterface $output
     * @return string
     */
    private function reconcileOne(int $id, bool $dryRun, OutputInterface $output): string
    {
        $line = sprintf('Capture row %d: ', $id);
        $this->logManager->setMerchantReference(null);

        try {
            $capture = $this->statusRepository->get($id);
            $line = sprintf(
                '%s %d, transaction %d: ',
                ucfirst((string)$capture->getRecordType()),
                $capture->getRecordId(),
                $capture->getTransactionId()
            );
            $transaction = $this->reconciler->findSettled($capture);

            if ($transaction === null) {
                $output->writeln($line . 'not settled at Qliro');

                return 'pending';
            }

            // A failed capture holds or cancels the order, which months later is for a person to decide
            if ($transaction->getStatus() !== QliroStatus::STATUS_SUCCESS) {
                $output->writeln($line . 'Qliro reports ' . $transaction->getStatus() . ', check the order by hand');

                return 'manual';
            }

            if ($dryRun || $this->reconciler->apply($capture, $transaction)) {
                $output->writeln($line . $transaction->getStatus() . ' ' . $transaction->getAmount());

                return 'applied';
            }

            $output->writeln($line . $transaction->getStatus() . ', not applied, see the Qliro log');
        } catch (\Throwable $exception) {
            $output->writeln($line . '<error>' . $exception->getMessage() . '</error>');
        }

        return 'failed';
    }

    /**
     * @param string|null $value
     * @return string|false|null
     */
    private function date($value)
    {
        if ($value === null) {
            return null;
        }

        $date = \DateTime::createFromFormat('!Y-m-d', (string)$value);

        return $date && $date->format('Y-m-d') === $value ? $date->format('Y-m-d H:i:s') : false;
    }
}
