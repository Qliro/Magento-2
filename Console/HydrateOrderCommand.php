<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

// @codingStandardsIgnoreFile
// phpcs:ignoreFile

namespace Qliro\QliroOne\Console;

use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Management\PlaceOrder;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Hydrate an existing Magento order directly with confirmed data from Qliro.
 *
 *
 * Usage:
 *   bin/magento qliroone:order:hydrate <qliro_order_id>
 *   bin/magento qliroone:order:hydrate <qliro_order_id> --dry-run
 *   bin/magento qliroone:order:hydrate <qliro_order_id> --status=Completed
 */
class HydrateOrderCommand extends AbstractCommand
{
    const COMMAND_RUN = 'qliroone:order:hydrate';

    private string  $qliroOrderId;
    private bool    $dry;
    private ?string $statusOverride;

    protected function configure(): void
    {
        parent::configure();

        $this->setName(self::COMMAND_RUN);
        $this->setDescription(
            'Hydrate an existing Magento order with confirmed Qliro data (addresses, shipping, payment, state). ' .
            'Calls hydrateAndFinalise() directly — no HTTP callback needed.'
        );

        $this->addArgument(
            'qliro_order_id',
            InputArgument::REQUIRED,
            'The Qliro order ID whose linked Magento order should be hydrated'
        );
        $this->addOption(
            'status',
            's',
            InputOption::VALUE_OPTIONAL,
            'Override the Qliro status used for the state transition ' .
            '(e.g. Completed, InProcess, OnHold, Refused). Defaults to the live Qliro status.'
        );
        $this->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Show what would change without writing anything to the database'
        );
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        parent::initialize($input, $output);
        $this->qliroOrderId   = (string) $input->getArgument('qliro_order_id');
        $this->dry            = (bool)   $input->getOption('dry-run');
        $this->statusOverride = $input->getOption('status') !== null ?
                                (string) $input->getOption('status') :
                                null;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $om = $this->getObjectManager();

        /** @var LinkRepositoryInterface $linkRepo */
        $linkRepo = $om->get(LinkRepositoryInterface::class);

        /** @var MerchantInterface $merchantApi */
        $merchantApi = $om->get(MerchantInterface::class);

        /** @var OrderRepositoryInterface $orderRepo */
        $orderRepo = $om->get(OrderRepositoryInterface::class);

        $output->writeln(sprintf(
            '<comment>qliroone:order:hydrate → Qliro order <info>%s</info>%s</comment>',
            $this->qliroOrderId,
            $this->dry ? '  <comment>[dry-run]</comment>' : ''
        ));

        try {
            $link = $linkRepo->getByQliroOrderId((int) $this->qliroOrderId, false);
        } catch (\Exception $e) {
            $output->writeln('<error>No link found for Qliro order ' . $this->qliroOrderId . ': ' . $e->getMessage() . '</error>');
            return 1;
        }

        $orderId = $link->getOrderId();
        if (empty($orderId)) {
            $output->writeln('<error>Link has no Magento order_id. placePending() may not have run yet.</error>');
            return 1;
        }

        $output->writeln(sprintf(
            '  Link     : reference=<info>%s</info>  order_id=<info>%s</info>  stored_status=<info>%s</info>',
            $link->getReference(),
            $orderId,
            $link->getQliroOrderStatus() ?: '(none)'
        ));

        $output->writeln('  Fetching Qliro order from Merchant API…');
        try {
            $qliroOrder = $merchantApi->getOrder((int) $this->qliroOrderId);
        } catch (\Exception $e) {
            $output->writeln('<error>Merchant API call failed: ' . $e->getMessage() . '</error>');
            return 1;
        }

        if (empty($qliroOrder) || empty($qliroOrder['CustomerCheckoutStatus'])) {
            $output->writeln(
                '<error>Qliro returned empty or expired order data (Status is null). ' .
                'Cannot hydrate — use this command while the order is still active in Qliro.</error>'
            );
            return 1;
        }

        $output->writeln(sprintf('  Qliro    : status=<info>%s</info>', $qliroOrder['CustomerCheckoutStatus']));

        $effectiveStatus = $this->statusOverride ?? $qliroOrder['CustomerCheckoutStatus'];
        if ($this->statusOverride !== null) {
            $output->writeln('  Using --status override: <info>' . $effectiveStatus . '</info>');
            $qliroOrder['Status'] = $effectiveStatus;
        }

        $order = $orderRepo->get($orderId);
        $output->writeln(sprintf(
            '  Order    : increment_id=<info>%s</info>  state=<info>%s</info>  status=<info>%s</info>',
            $order->getIncrementId(),
            $order->getState(),
            $order->getStatus()
        ));

        $output->writeln('');
        $output->writeln('  <comment>Planned changes:</comment>');
        $this->printDiff($output, $order, $qliroOrder, $effectiveStatus);

        if ($this->dry) {
            $output->writeln('');
            $output->writeln('<comment>[dry-run] Nothing written to the database.</comment>');
            return 0;
        }

        $link->setQliroOrderStatus($effectiveStatus);
        $linkRepo->save($link);

        /** @var PlaceOrder $placeOrder */
        $placeOrder = $om->get(PlaceOrder::class);

        try {
            $placeOrder->hydrateAndFinalise($order, $qliroOrder);
        } catch (\Exception $e) {
            $output->writeln('<error>hydrateAndFinalise failed: ' . $e->getMessage() . '</error>');
            return 1;
        }

        $output->writeln('');
        $output->writeln('<info>Order hydrated and finalised successfully.</info>');
        return 0;
    }

    private function printDiff(
        OutputInterface $output,
        Order $order,
        array $qliroOrder,
        string $effectiveStatus
    ): void {
        $qliroCustomer = $qliroOrder['Customer'] ?? [];
        $qliroBilling  = $qliroOrder['BillingAddress'] ?? ($qliroCustomer['Address'] ?? []);
        $qliroShipping = $qliroOrder['ShippingAddress'] ?? $qliroBilling;

        if (!$qliroCustomer && !$qliroBilling) {
            $output->writeln('    Addresses  : <comment>(no Customer/BillingAddress data — will be skipped)</comment>');
        } else {
            $this->printAddressDiff($output, $order->getBillingAddress(),  $qliroBilling,  $qliroCustomer, 'Billing');
            $this->printAddressDiff($output, $order->getShippingAddress(), $qliroShipping, $qliroCustomer, 'Shipping');
        }

        $selected = $qliroOrder['SelectedShippingMethod'] ?? null;
        if ($selected) {
            $newCode  = $selected['MerchantReference'] ?? null;
            $newPrice = isset($selected['PriceIncVat']) ? (float) $selected['PriceIncVat'] : null;
            $output->writeln(sprintf(
                '    Shipping   : method <comment>%s</comment> → <info>%s</info>  price incl. tax <comment>%s</comment> → <info>%s</info>',
                $order->getShippingMethod() ?: '(none)',
                $newCode ?: '(none)',
                number_format((float) $order->getShippingInclTax(), 2),
                $newPrice !== null ? number_format($newPrice, 2) : '(unchanged)'
            ));
        } else {
            $output->writeln('    Shipping   : <comment>(no SelectedShippingMethod — unchanged)</comment>');
        }

        $pm = $qliroOrder['PaymentMethod'] ?? [];
        if ($pm) {
            $output->writeln(sprintf(
                '    Payment    : type=<info>%s</info>  name=<info>%s</info>',
                $pm['PaymentTypeCode'] ?? '?',
                $pm['PaymentMethodName'] ?? '?'
            ));
        }

        $stateMap = [
            'Completed' => Order::STATE_NEW,
            'OnHold'    => Order::STATE_PAYMENT_REVIEW,
            'Refused'   => 'canceled',
            'InProcess' => '(no change)',
        ];
        $targetState = $stateMap[$effectiveStatus] ?? '(no change)';
        $output->writeln(sprintf(
            '    State      : <comment>%s/%s</comment> → <info>%s</info>  (Qliro status: <info>%s</info>)',
            $order->getState(),
            $order->getStatus(),
            $targetState,
            $effectiveStatus
        ));
    }

    private function printAddressDiff(
        OutputInterface $output,
        ?\Magento\Sales\Model\Order\Address $current,
        array $qliroAddress,
        array $qliroCustomer,
        string $label
    ): void {
        if (!$current || empty($qliroAddress)) {
            return;
        }

        $fields = [
            'firstname' => [$current->getFirstname(),    $qliroAddress['FirstName']        ?? null],
            'lastname'  => [$current->getLastname(),     $qliroAddress['LastName']         ?? null],
            'email'     => [$current->getEmail(),        $qliroCustomer['Email']           ?? null],
            'street'    => [$current->getStreetLine(1),  $qliroAddress['Street']           ?? null],
            'city'      => [$current->getCity(),         $qliroAddress['City']             ?? null],
            'postcode'  => [$current->getPostcode(),     $qliroAddress['PostalCode']       ?? null],
            'telephone' => [$current->getTelephone(),    $qliroCustomer['MobileNumber']    ?? null],
            'company'   => [$current->getCompany(),      $qliroAddress['CompanyName']      ?? null],
        ];

        $changes = [];
        foreach ($fields as $key => [$before, $after]) {
            if ($after !== null && (string) $before !== (string) $after) {
                $changes[] = sprintf(
                    '      %-10s: <comment>%s</comment> → <info>%s</info>',
                    $key,
                    $before ?: '(empty)',
                    $after
                );
            }
        }

        if ($changes) {
            $output->writeln('    ' . $label . ' address changes:');
            foreach ($changes as $line) {
                $output->writeln($line);
            }
        } else {
            $output->writeln('    ' . $label . ' address  : <info>(no changes)</info>');
        }
    }
}
