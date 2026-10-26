<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

// @codingStandardsIgnoreFile
// phpcs:ignoreFile

namespace Qliro\QliroOne\Console;

use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Management;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Qliro\QliroOne\Model\Api\Client\Merchant;

/**
 * Class GetOrderCommand
 * Test apis.
 */
class GetOrderCommand extends AbstractCommand
{
    const COMMAND_RUN = 'qliroone:api:getorder';

    /**
     * @var string
     */
    private $orderId;

    /**
     * @var boolean
     */
    private $createMagentoOrder;

    /**
     * Configure the CLI command
     */
    protected function configure()
    {
        parent::configure();

        $this->setName(self::COMMAND_RUN);
        $this->setDescription('Get Order from Qliro');
        $this->addArgument('orderid', InputArgument::REQUIRED, 'Existing Qliro order id', null);
        $this->addArgument('create', InputArgument::OPTIONAL, 'Create Magento order from Qliro order', false);
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
        $this->createMagentoOrder = filter_var(
            $input->getArgument('create'),
            FILTER_VALIDATE_BOOLEAN,
            FILTER_NULL_ON_FAILURE
        );
        if ($this->createMagentoOrder === null) {
            throw new InvalidArgumentException('create takes 1, true, yes or on, or 0, false, no or off');
        }
    }

    /**
     * Execute the command
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->writeln('<comment>Getting QliroOne order</comment>');

        /** @var Merchant $merchant */
        $merchant = $this->getObjectManager()->get(Merchant::class);

        /** @var \Qliro\QliroOne\Model\ContainerMapper $containerMapper */
        $containerMapper = $this->getObjectManager()->get(ContainerMapper::class);

        try {
            $responseContainer = $merchant->getOrder($this->orderId);
            $responsePayload = $containerMapper->toArray($responseContainer);

            $this->writeText($output, $this->toJson($responsePayload));

            if ($this->createMagentoOrder) {
                /** @var \Qliro\QliroOne\Model\Management $management */
                $management = $this->getObjectManager()->get(Management::class);

                $management->placeOrder($responseContainer);
                $output->writeln('<comment>Place order successfull</comment>');
            }
        } catch (\Qliro\QliroOne\Model\Exception\TerminalException $exception) {
            // Merchant turns its own into a ClientException, so this one comes from the placement
            $this->writeText($output, 'Order placement failed:');
            $this->writeFailure($output, $exception);

            return 1;
        } catch (\Qliro\QliroOne\Model\Exception\FailToLockException $exception) {
            $this->writeText($output, 'Locking:');
            $this->writeText($output, $exception->getMessage());

            return 1;
        } catch (\Qliro\QliroOne\Model\Api\Client\Exception\ClientException $exception) {
            $this->writeApiFailure($output, $exception);

            return 1;
        }

        return 0;
    }
}
