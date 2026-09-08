<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Console;

use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Service\Log\Pruner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prunes the qliroone_log table on demand, the same way the qliroone_prune_log cron job does
 */
class PruneLogCommand extends Command
{
    const COMMAND_RUN = 'qliroone:log:prune';

    const OPTION_DAYS = 'days';

    /**
     * @param Pruner $pruner
     * @param Config $qliroConfig
     * @param string|null $name
     */
    public function __construct(
        private readonly Pruner $pruner,
        private readonly Config $qliroConfig,
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
        $this->setDescription('Delete qliroone_log rows older than the configured retention');
        $this->addOption(
            self::OPTION_DAYS,
            'd',
            InputOption::VALUE_REQUIRED,
            'Retention in days for this run, instead of the configured one. 0 deletes nothing'
        );

        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $given = $input->getOption(self::OPTION_DAYS);
        $days = $given === null ? null : $this->qliroConfig->parseLogRetentionDays($given);

        if ($given !== null && $days === null) {
            $output->writeln(sprintf(
                '<error>--days must be a whole number of days between 0 and %d</error>',
                Config::MAX_LOG_RETENTION_DAYS
            ));

            return 1;
        }

        $retentionDays = $this->pruner->resolveRetentionDays($days);
        $deleted = $this->pruner->prune($retentionDays);

        $output->writeln(
            $retentionDays > 0
                ? sprintf('<info>Deleted %d log rows older than %d days</info>', $deleted, $retentionDays)
                : '<comment>Retention is 0, every log row is kept</comment>'
        );

        if ($this->pruner->hasBacklog($retentionDays)) {
            $output->writeln('<comment>The batch cap was reached, run this again to continue</comment>');
        }

        return 0;
    }
}
