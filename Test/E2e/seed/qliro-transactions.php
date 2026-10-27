<?php
/**
 * Prints what Qliro has reserved and captured on an order, read over the admin API.
 *
 * Usage, inside the Magento container:
 *   php var/qliro-transactions.php --qliro-order=<Qliro order id>
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;

require '/var/www/html/app/bootstrap.php';

$options = getopt('', ['qliro-order:']);

$om = Bootstrap::create(BP, [])->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

$order = $om->get(\Qliro\QliroOne\Api\Client\OrderManagementInterface::class)
    ->getOrder((int)$options['qliro-order']);
$totals = ['reserved' => 0.0, 'captured' => 0.0];

foreach ((array)$order->getPaymentTransactions() as $transaction) {
    if ($transaction->getStatus() !== 'Success') {
        continue;
    }

    if ($transaction->getType() === 'Preauthorization') {
        $totals['reserved'] += (float)$transaction->getAmount();
    } elseif ($transaction->getType() === 'Capture') {
        $totals['captured'] += (float)$transaction->getAmount();
    }
}

echo json_encode($totals) . PHP_EOL;
