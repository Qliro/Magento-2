<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Console;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Qliro\QliroOne\Model\QliroOrder\Admin\InvoiceCaptureRelinker;
use Qliro\QliroOne\Model\ResourceModel\AdoptedCaptureInvoices;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Moves invoices that carry a Qliro preauthorization onto the capture, so they can be refunded
 */
class RelinkCapturesCommand extends Command
{
    const COMMAND_RUN = 'qliroone:capture:relink';

    const OPTION_FROM = 'from';
    const OPTION_TO = 'to';
    const OPTION_ORDER = 'order';
    const OPTION_DRY_RUN = 'dry-run';

    private const PAGE = 100;

    /**
     * @param AdoptedCaptureInvoices $invoices
     * @param InvoiceCaptureRelinker $relinker
     * @param State $appState
     * @param string|null $name
     */
    public function __construct(
        private readonly AdoptedCaptureInvoices $invoices,
        private readonly InvoiceCaptureRelinker $relinker,
        private readonly State $appState,
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
        $this->setDescription('Link invoices that carry a Qliro preauthorization to their capture');
        $this->addOption(self::OPTION_FROM, null, InputOption::VALUE_REQUIRED, 'Invoices created on or after this UTC date, Y-m-d');
        $this->addOption(self::OPTION_TO, null, InputOption::VALUE_REQUIRED, 'Invoices created before this UTC date, Y-m-d');
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

        // Every order is a call to Qliro
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
        $counts = [
            InvoiceCaptureRelinker::RELINKED => 0,
            InvoiceCaptureRelinker::TRACKED => 0,
            InvoiceCaptureRelinker::CAPTURE => 0,
            InvoiceCaptureRelinker::PENDING => 0,
            InvoiceCaptureRelinker::MANUAL => 0,
            InvoiceCaptureRelinker::FAILED => 0,
        ];
        $afterId = 0;

        while ($ids = $this->invoices->find($from, $to, $orders, $afterId, self::PAGE)) {
            foreach ($ids as $orderId) {
                $afterId = $orderId;

                try {
                    foreach ($this->relinker->relink($orderId, $dryRun) as $line) {
                        $counts[$line['outcome']]++;
                        $output->writeln(sprintf(
                            'Invoice %s, transaction %s: %s%s, %s',
                            $line['invoice'],
                            $line['from'],
                            $line['outcome'],
                            $line['to'] !== null ? ' ' . $line['to'] : '',
                            $line['note']
                        ));
                    }
                } catch (\Throwable $exception) {
                    $counts[InvoiceCaptureRelinker::FAILED]++;
                    $output->writeln(sprintf('Order %d: <error>%s</error>', $orderId, $exception->getMessage()));
                }
            }
        }

        $output->writeln(sprintf(
            '<info>%s %d, captured amount %s %d, left on their capture %d, not settled at Qliro %d, '
            . 'to check by hand %d, failed %d</info>',
            $dryRun ? 'Would relink' : 'Relinked',
            $counts[InvoiceCaptureRelinker::RELINKED],
            $dryRun ? 'to record' : 'recorded',
            $counts[InvoiceCaptureRelinker::TRACKED],
            $counts[InvoiceCaptureRelinker::CAPTURE],
            $counts[InvoiceCaptureRelinker::PENDING],
            $counts[InvoiceCaptureRelinker::MANUAL],
            $counts[InvoiceCaptureRelinker::FAILED]
        ));

        return $counts[InvoiceCaptureRelinker::FAILED] > 0 ? 1 : 0;
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
