<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

// @codingStandardsIgnoreFile
// phpcs:ignoreFile

namespace Qliro\QliroOne\Console;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class UpdateOrderCommand
 * Test apis.
 */
class UpdateOrderCommand extends AbstractCommand
{
    const COMMAND_RUN = 'qliroone:api:updateorder';

    /**
     * @var string
     */
    private $orderId;

    /**
     * @var boolean
     */
    private $force;

    /**
     * Configure the CLI command
     */
    protected function configure()
    {
        parent::configure();

        $this->setName(self::COMMAND_RUN);
        $this->setDescription('Update Order in Qliro based on quote');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'always send update to qliro');
        $this->addArgument('orderid', InputArgument::REQUIRED, 'Existing Qliro order id', null);
    }

    /**
     * Initialize the command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        parent::initialize($input, $output);
        $this->orderId = $input->getArgument('orderid');
        $this->force = $input->getOption('force');
    }

    /**
     * Execute the command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->writeln('<comment>Update QliroOne order</comment>');

        /** @var \Qliro\QliroOne\Api\Admin\OrderServiceInterface $management */
        $management = $this->getObjectManager()->get(\Qliro\QliroOne\Api\Admin\OrderServiceInterface::class);

        try {
            $qliroOrder = $management->getAdminQliroOrder((int)$this->orderId);
            $output->writeln('<info>Qliro order: ' . ($qliroOrder ? $qliroOrder->getOrderId() : 'not found') . '</info>');
        } catch (\Qliro\QliroOne\Model\Api\Client\Exception\ClientException $exception) {
            /** @var \GuzzleHttp\Exception\RequestException $origException */
            $origException = $exception->getPrevious();

            fprintf(STDOUT, 'Merchant API exception:');

            fprintf(
                STDOUT,
                \json_encode(
                    [
                        'request.uri' => $origException->getRequest()->getUri(),
                        'request.method' => $origException->getRequest()->getMethod(),
                        'request.headers' => $origException->getRequest()->getHeaders(),
                        'request.body' => $origException->getRequest()->getBody()->getContents(),
                        'response.status' => $origException->getResponse()->getStatusCode(),
                        'response.headers' => $origException->getResponse()->getHeaders(),
                        'response.body' => $origException->getResponse()->getBody()->getContents(),
                    ],
                    JSON_PRETTY_PRINT
                )
            );
        }

        return 0;
    }
}
