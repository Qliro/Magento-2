<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Console;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\State;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Management\CheckoutStatus;
use Qliro\QliroOne\Model\Management\TransactionStatus;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Local-development poller that simulates Qliro's server-to-server callbacks.
 *
 * On production Qliro PUSHES CheckoutStatus / TransactionStatus callbacks to the store.
 * Locally Qliro cannot reach the dev machine, so this command PULLS the current order
 * state from the Qliro API and dispatches the matching callback handler directly
 * (no HTTP), making the checkout/capture/refund flow behave "like production".
 *
 * Start it in a terminal before testing and leave it running:
 *   bin/magento qliroone:callback:poll --watch
 *
 * Single pass:
 *   bin/magento qliroone:callback:poll
 *
 * Guard: refuses to run unless app mode is 'developer' AND
 * payment/qliroone/dev/auto_callback is enabled. It can never run on production.
 */
class PollCallbackCommand extends AbstractCommand
{
    const COMMAND_RUN = 'qliroone:callback:poll';

    /**
     * In-memory dedup for transaction statuses dispatched during this run,
     * keyed by "<txnId>:<status>", so a watch loop doesn't re-fire the same
     * transaction status on every tick.
     *
     * @var array<string, true>
     */
    private array $dispatchedTransactions = [];

    protected function configure(): void
    {
        parent::configure();

        $this->setName(self::COMMAND_RUN);
        $this->setDescription(
            'Local-dev only: poll Qliro for order status and dispatch callbacks (CheckoutStatus / TransactionStatus). '
            . 'Requires developer mode and payment/qliroone/dev/auto_callback=1.'
        );

        $this->addOption('watch', 'w', InputOption::VALUE_NONE, 'Keep polling in a loop until interrupted (Ctrl+C)');
        $this->addOption('interval', 'i', InputOption::VALUE_OPTIONAL, 'Seconds between polls in --watch mode', '5');
        $this->addOption('hours', null, InputOption::VALUE_OPTIONAL, 'Only poll links created within the last N hours', '6');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $om = $this->getObjectManager();

        /** @var State $appState */
        $appState = $om->get(State::class);
        /** @var Config $config */
        $config = $om->get(Config::class);

        // Guard 1: developer mode only.
        if ($appState->getMode() !== State::MODE_DEVELOPER) {
            $output->writeln('<error>Refusing to run: Magento is not in developer mode.</error>');
            $output->writeln('<comment>This command is for local development only.</comment>');
            return 1;
        }

        // Guard 2: explicit opt-in flag.
        if (!$config->isDevAutoCallbackEnabled()) {
            $output->writeln('<error>Refusing to run: payment/qliroone/dev/auto_callback is disabled.</error>');
            $output->writeln('<comment>Enable it with: bin/magento config:set payment/qliroone/dev/auto_callback 1</comment>');
            return 1;
        }

        $watch    = (bool) $input->getOption('watch');
        $interval = max(1, (int) $input->getOption('interval'));
        $hours    = max(1, (int) $input->getOption('hours'));

        if (!$watch) {
            $this->pollOnce($om, $output, $hours);
            return 0;
        }

        $output->writeln(sprintf(
            '<info>Watching for Qliro order updates every %ds (links from last %dh). Press Ctrl+C to stop.</info>',
            $interval,
            $hours
        ));

        while (true) {
            try {
                $this->pollOnce($om, $output, $hours);
            } catch (\Throwable $e) {
                // Never let a single bad poll kill the watch loop.
                $output->writeln('<error>Poll error: ' . $e->getMessage() . '</error>');
            }
            sleep($interval);
        }
    }

    /**
     * One polling pass over the active links.
     */
    private function pollOnce($om, OutputInterface $output, int $hours): void
    {
        /** @var LinkRepositoryInterface $linkRepo */
        $linkRepo = $om->get(LinkRepositoryInterface::class);
        /** @var SearchCriteriaBuilder $scb */
        $scb = $om->create(SearchCriteriaBuilder::class);

        $scb->addFilter('is_active', 1);
        $scb->addFilter('created_at', date('Y-m-d H:i:s', strtotime('-' . $hours . ' hours')), 'gteq');
        $links = $linkRepo->getList($scb->create())->getItems();

        if (empty($links)) {
            return;
        }

        /** @var MerchantInterface $merchantApi */
        $merchantApi = $om->get(MerchantInterface::class);

        foreach ($links as $link) {
            $qliroOrderId = (int) $link->getQliroOrderId();
            if (!$qliroOrderId) {
                continue;
            }

            try {
                $qliroOrder = $merchantApi->getOrder($qliroOrderId);
            } catch (\Exception $e) {
                // Order may have expired in Qliro's sandbox — nothing to dispatch.
                continue;
            }

            $this->dispatchCheckoutStatus($om, $output, $link, $qliroOrder);
            $this->dispatchTransactionStatuses($om, $output, $link, $qliroOrder);
        }
    }

    /**
     * Fire CheckoutStatus::update() when Qliro's live status differs from what we stored.
     */
    private function dispatchCheckoutStatus($om, OutputInterface $output, LinkInterface $link, array $qliroOrder): void
    {
        $liveStatus = $qliroOrder['Status'] ?? null;
        if (!$liveStatus || $liveStatus === $link->getQliroOrderStatus()) {
            return;
        }

        $payload = [
            'OrderId'           => (int) $link->getQliroOrderId(),
            'MerchantReference' => $link->getReference(),
            'Status'            => $liveStatus,
            'Timestamp'         => gmdate('Y-m-d\TH:i:s\Z'),
        ];

        /** @var CheckoutStatus $checkoutStatus */
        $checkoutStatus = $om->get(CheckoutStatus::class);
        $checkoutStatus->update($payload);

        $output->writeln(sprintf(
            '  <info>CheckoutStatus</info> %s -> <info>%s</info> (qliro #%d, ref %s)',
            $link->getQliroOrderStatus() ?: '(none)',
            $liveStatus,
            (int) $link->getQliroOrderId(),
            $link->getReference()
        ));
    }

    /**
     * Fire TransactionStatus::handle() for each Qliro payment transaction whose status
     * we have not already recorded locally (captures, refunds, etc.).
     */
    private function dispatchTransactionStatuses($om, OutputInterface $output, LinkInterface $link, array $qliroOrder): void
    {
        $transactions = $qliroOrder['PaymentTransactions'] ?? [];
        if (empty($transactions)) {
            return;
        }

        $known = $this->knownTransactionStatuses($om, (int) $link->getQliroOrderId());

        /** @var TransactionStatus $transactionStatus */
        $transactionStatus = $om->get(TransactionStatus::class);

        foreach ($transactions as $tx) {
            $txId     = $tx['PaymentTransactionId'] ?? null;
            $txStatus = $tx['Status'] ?? null;
            if (!$txId || !$txStatus) {
                continue;
            }

            $dedupKey = $txId . ':' . $txStatus;
            if (isset($this->dispatchedTransactions[$dedupKey]) || ($known[$txId] ?? null) === $txStatus) {
                continue;
            }

            $payload = [
                'OrderId'              => (int) $link->getQliroOrderId(),
                'MerchantReference'    => $link->getReference(),
                'PaymentTransactionId' => $txId,
                'Status'               => $txStatus,
                'Amount'               => $tx['Amount'] ?? null,
                'PaymentType'          => $tx['Type'] ?? null,
            ];

            $transactionStatus->handle($payload);
            $this->dispatchedTransactions[$dedupKey] = true;

            $output->writeln(sprintf(
                '  <info>TransactionStatus</info> txn %s -> <info>%s</info> (qliro #%d)',
                $txId,
                $txStatus,
                (int) $link->getQliroOrderId()
            ));
        }
    }

    /**
     * Build a map of transactionId => latest recorded status from the local OM status table,
     * so we don't re-dispatch a transaction status that has already been processed.
     *
     * @return array<int, string>
     */
    private function knownTransactionStatuses($om, int $qliroOrderId): array
    {
        $map = [];
        try {
            /** @var SearchCriteriaBuilder $scb */
            $scb = $om->create(SearchCriteriaBuilder::class);
            $scb->addFilter(OrderManagementStatusInterface::FIELD_QLIRO_ORDER_ID, $qliroOrderId);

            /** @var OrderManagementStatusRepositoryInterface $omsRepo */
            $omsRepo = $om->get(OrderManagementStatusRepositoryInterface::class);
            foreach ($omsRepo->getList($scb->create())->getItems() as $record) {
                $txId = $record->getTransactionId();
                if ($txId) {
                    $map[$txId] = $record->getTransactionStatus();
                }
            }
        } catch (\Exception $e) {
            // Non-fatal — without the map we may re-dispatch, but TransactionStatus::handle
            // dedups internally (marks repeats as SKIPPED).
        }

        return $map;
    }
}
