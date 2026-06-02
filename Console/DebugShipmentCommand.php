<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Console;

use Magento\Sales\Api\OrderRepositoryInterface;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Payload\PayloadConverter;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\InvoiceMarkItemsAsShippedRequestBuilder;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Diagnose "Property: Shipments[0].OrderItems is invalid" by dumping the shipment
 * payload alongside the live Qliro order — so a mismatched merchant reference,
 * price, or qty is immediately visible.
 *
 * Usage:
 *   bin/magento qliroone:debug:shipment <magento_order_id_or_increment_id>
 */
class DebugShipmentCommand extends AbstractCommand
{
    const COMMAND_RUN = 'qliroone:debug:shipment';

    protected function configure(): void
    {
        parent::configure();
        $this->setName(self::COMMAND_RUN);
        $this->setDescription(
            'Dump the shipment payload that would be sent to Qliro alongside the live Qliro order, '
            . 'highlighting items by MerchantReference + price. Useful for diagnosing INVALID_ITEM / '
            . '"OrderItems is invalid" errors.'
        );
        $this->addArgument(
            'order',
            InputArgument::REQUIRED,
            'Magento order entity_id or increment_id'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $om = $this->getObjectManager();

        $orderArg = (string) $input->getArgument('order');

        /** @var OrderRepositoryInterface $orderRepo */
        $orderRepo = $om->get(OrderRepositoryInterface::class);

        try {
            $order = ctype_digit($orderArg)
                ? $orderRepo->get((int) $orderArg)
                : $this->loadByIncrementId($om, $orderArg);
        } catch (\Exception $e) {
            $output->writeln('<error>Order not found: ' . $orderArg . '</error>');
            return 1;
        }

        $output->writeln(sprintf(
            '<info>Order:</info> #%s (entity_id %d) — state: <info>%s</info>',
            $order->getIncrementId(),
            $order->getId(),
            $order->getState()
        ));

        /** @var LinkRepositoryInterface $linkRepo */
        $linkRepo = $om->get(LinkRepositoryInterface::class);
        try {
            $link = $linkRepo->getByOrderId($order->getId());
        } catch (\Exception $e) {
            $output->writeln('<error>No Qliro link for this order.</error>');
            return 1;
        }

        $qliroOrderId = (int) $link->getQliroOrderId();
        $output->writeln(sprintf(
            '<info>Qliro order:</info> %d (ref %s)',
            $qliroOrderId,
            $link->getReference()
        ));
        $output->writeln('');

        // === LOCAL: what the shipment builder would send ===
        $payment = $order->getPayment();
        if ($payment === null) {
            $output->writeln('<error>Order has no payment.</error>');
            return 1;
        }

        /** @var InvoiceMarkItemsAsShippedRequestBuilder $builder */
        $builder = $om->get(InvoiceMarkItemsAsShippedRequestBuilder::class);
        $builder->setPayment($payment);
        $builder->setAmount((float) $order->getGrandTotal());

        try {
            $request = $builder->create();
        } catch (\Exception $e) {
            $output->writeln('<error>Building shipment payload failed: ' . $e->getMessage() . '</error>');
            return 1;
        }

        /** @var PayloadConverter $converter */
        $converter = $om->get(PayloadConverter::class);
        $payload = $converter->toArray($request);

        $output->writeln('<comment>== LOCAL: shipment payload (would be POSTed to /MarkItemsAsShipped) ==</comment>');
        $localItems = $this->extractShipmentItems($payload);
        $this->renderItems($output, $localItems);

        // === REMOTE: what Qliro currently has on the order ===
        $output->writeln('');
        $output->writeln('<comment>== REMOTE: Qliro order items (live from /merchantapi/orders/' . $qliroOrderId . ') ==</comment>');

        try {
            /** @var MerchantInterface $merchantApi */
            $merchantApi = $om->get(MerchantInterface::class);
            $qliroOrder = $merchantApi->getOrder($qliroOrderId);
            $remoteItems = $this->extractQliroItems($qliroOrder);
            $this->renderItems($output, $remoteItems);
        } catch (\Exception $e) {
            $output->writeln('<error>Could not fetch Qliro order: ' . $e->getMessage() . '</error>');
            return 1;
        }

        // === DIFF ===
        $output->writeln('');
        $output->writeln('<comment>== DIFF (local shipment item not present in Qliro order with matching ref + price) ==</comment>');
        $this->renderDiff($output, $localItems, $remoteItems);

        return 0;
    }

    /**
     * Pull (ref, price, qty) from the shipment payload's Shipments[].OrderItems[].
     *
     * @return array<int, array{ref:string, price:float, qty:float}>
     */
    private function extractShipmentItems(array $payload): array
    {
        $items = [];
        foreach (($payload['Shipments'] ?? []) as $shipment) {
            foreach (($shipment['OrderItems'] ?? []) as $item) {
                $items[] = [
                    'ref'   => (string) ($item['MerchantReference'] ?? ''),
                    'price' => (float)  ($item['PricePerItemIncVat'] ?? 0),
                    'qty'   => (float)  ($item['Quantity'] ?? 0),
                ];
            }
        }
        return $items;
    }

    /**
     * @return array<int, array{ref:string, price:float, qty:float, type:string}>
     */
    private function extractQliroItems(array $qliroOrder): array
    {
        $items = [];
        foreach (($qliroOrder['OrderItems'] ?? []) as $item) {
            $items[] = [
                'ref'   => (string) ($item['MerchantReference'] ?? ''),
                'price' => (float)  ($item['PricePerItemIncVat'] ?? 0),
                'qty'   => (float)  ($item['Quantity'] ?? 0),
                'type'  => (string) ($item['Type'] ?? ''),
            ];
        }
        return $items;
    }

    /**
     * @param array<int, array{ref:string, price:float, qty:float, type?:string}> $items
     */
    private function renderItems(OutputInterface $output, array $items): void
    {
        if (empty($items)) {
            $output->writeln('  <error>(no items)</error>');
            return;
        }
        foreach ($items as $item) {
            $type = isset($item['type']) ? ' [' . $item['type'] . ']' : '';
            $output->writeln(sprintf(
                '  ref=<info>%s</info>%s  price=<info>%.2f</info>  qty=<info>%g</info>',
                $item['ref'],
                $type,
                $item['price'],
                $item['qty']
            ));
        }
    }

    /**
     * Item matches when both MerchantReference AND PricePerItemIncVat match
     * (Qliro requires both — that's exactly what "Could not find a matching item with ref X and price Y" means).
     *
     * @param array<int, array{ref:string, price:float, qty:float}> $local
     * @param array<int, array{ref:string, price:float, qty:float}> $remote
     */
    private function renderDiff(OutputInterface $output, array $local, array $remote): void
    {
        $missing = [];
        foreach ($local as $l) {
            $found = false;
            foreach ($remote as $r) {
                if ($l['ref'] === $r['ref'] && abs($l['price'] - $r['price']) < 0.005) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missing[] = $l;
            }
        }

        if (empty($missing)) {
            $output->writeln('  <info>All local shipment items match a Qliro item by (ref, price). Mismatch must be in qty or elsewhere.</info>');
            return;
        }

        $output->writeln('  <error>Local items WITHOUT a matching (ref, price) in the Qliro order:</error>');
        foreach ($missing as $m) {
            $output->writeln(sprintf(
                '    ref=<error>%s</error>  price=<error>%.2f</error>  qty=%g',
                $m['ref'],
                $m['price'],
                $m['qty']
            ));
        }
        $output->writeln('  <comment>(this is exactly what Qliro rejects with INVALID_ITEM "Could not find a matching item ...")</comment>');
    }

    private function loadByIncrementId($om, string $incrementId)
    {
        /** @var \Magento\Sales\Api\OrderRepositoryInterface $orderRepo */
        $orderRepo = $om->get(OrderRepositoryInterface::class);
        /** @var \Magento\Framework\Api\SearchCriteriaBuilder $scb */
        $scb = $om->create(\Magento\Framework\Api\SearchCriteriaBuilder::class);
        $scb->addFilter('increment_id', $incrementId);
        $list = $orderRepo->getList($scb->create())->getItems();
        if (empty($list)) {
            throw new \RuntimeException('Order not found');
        }
        return reset($list);
    }
}
