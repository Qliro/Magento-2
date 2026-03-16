<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

// @codingStandardsIgnoreFile
// phpcs:ignoreFile

namespace Qliro\QliroOne\Console;

use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\QliroOrder\Builder\UpdateRequestBuilder;
use Magento\Quote\Api\CartRepositoryInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Push current quote state (OrderItems + AvailableShippingMethods) to Qliro.
 *
 * Useful for local testing when the updateCustomer callback cannot reach localhost.
 *
 * Usage:
 *   bin/magento qliroone:checkout:push-update <qliro_order_id>
 *   bin/magento qliroone:checkout:push-update <qliro_order_id> --dry-run
 */
class PushQuoteUpdateCommand extends AbstractCommand
{
    const COMMAND_NAME = 'qliroone:checkout:push-update';

    private string $qliroOrderId;
    private bool   $dry;

    protected function configure(): void
    {
        parent::configure();

        $this->setName(self::COMMAND_NAME);
        $this->setDescription(
            '[Dev] Push current quote state (items + shipping methods) to Qliro for an in-progress checkout order.'
        );
        $this->addArgument(
            'qliro_order_id',
            InputArgument::REQUIRED,
            'The Qliro order ID to update'
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Build and print the payload without sending it to Qliro'
        );
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
        $this->qliroOrderId = (string) $input->getArgument('qliro_order_id');
        $this->dry          = (bool)   $input->getOption('dry-run');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $om = $this->getObjectManager();

        /** @var LinkRepositoryInterface $linkRepo */
        $linkRepo = $om->get(LinkRepositoryInterface::class);

        /** @var CartRepositoryInterface $quoteRepo */
        $quoteRepo = $om->get(CartRepositoryInterface::class);

        /** @var UpdateRequestBuilder $updateBuilder */
        $updateBuilder = $om->get(UpdateRequestBuilder::class);

        /** @var MerchantInterface $merchantApi */
        $merchantApi = $om->get(MerchantInterface::class);

        $output->writeln(sprintf(
            '<comment>qliroone:checkout:push-update → Qliro order <info>%s</info>%s</comment>',
            $this->qliroOrderId,
            $this->dry ? '  <comment>[dry-run]</comment>' : ''
        ));

        try {
            $link = $linkRepo->getByQliroOrderId((int) $this->qliroOrderId, false);
        } catch (\Exception $e) {
            $output->writeln('<error>No link found: ' . $e->getMessage() . '</error>');
            return 1;
        }

        $output->writeln(sprintf(
            '  Link  : reference=<info>%s</info>  quote_id=<info>%s</info>',
            $link->getReference(),
            $link->getQuoteId()
        ));

        try {
            $quote = $quoteRepo->get($link->getQuoteId());
        } catch (\Exception $e) {
            $output->writeln('<error>Quote not found: ' . $e->getMessage() . '</error>');
            return 1;
        }

        $payload = $updateBuilder->setQuote($quote)->create();

        $methods = $payload['AvailableShippingMethods'] ?? [];
        $output->writeln(sprintf(
            '  Items : <info>%d</info>   Shipping methods: <info>%d</info>',
            count($payload['OrderItems'] ?? []),
            count($methods)
        ));

        foreach ($methods as $m) {
            $output->writeln(sprintf(
                '    - <info>%s</info>  %s  %s kr',
                $m['MerchantReference'] ?? '?',
                $m['DisplayName'] ?? '',
                $m['PriceIncVat'] ?? '?'
            ));
        }

        if ($this->dry) {
            $output->writeln('');
            $output->writeln('<comment>[dry-run] Payload (not sent):</comment>');
            $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return 0;
        }

        try {
            $merchantApi->updateOrder((int) $this->qliroOrderId, $payload);
            $output->writeln('<info>Update sent to Qliro successfully.</info>');
        } catch (\Exception $e) {
            $output->writeln('<error>Merchant API call failed: ' . $e->getMessage() . '</error>');
            return 1;
        }

        return 0;
    }
}
