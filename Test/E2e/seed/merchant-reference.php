<?php
/**
 * What the module did about the merchant reference of one order, and a way to lose its mark
 *
 * Usage, inside the Magento container:
 *   php var/merchant-reference.php state --order=ID
 *       the mark, the updatemerchantreference calls for its Qliro order with how each ended,
 *       and the criticals about the reference
 *   php var/merchant-reference.php forget --order=ID
 *       removes the mark, as a store whose order save failed after the call is left
 *   php var/merchant-reference.php token
 *       the callback token
 *
 * Prints one JSON object.
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;

require '/var/www/html/app/bootstrap.php';

$command = $argv[1] ?? '';
// getopt stops at the command, so the option is read by hand
$orderId = 0;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--order=')) {
        $orderId = (int)substr($arg, 8);
    }
}

$om = Bootstrap::create(BP, [])->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');

if ($command === 'token') {
    echo json_encode(['token' => $om->get(\Qliro\QliroOne\Model\Security\CallbackToken::class)->getToken()]), PHP_EOL;
    exit(0);
}

$key = 'qliroone_updated_merchant_reference';
$order = $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->get($orderId);
$payment = $order->getPayment();

if ($command === 'forget') {
    $payment->unsAdditionalInformation($key);
    $om->get(\Magento\Sales\Api\OrderPaymentRepositoryInterface::class)->save($payment);
}

$qliroOrderId = (string)$payment->getAdditionalInformation('qliro_order_id');
$connection = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
$log = $connection->getTableName('qliroone_log');
$rows = $connection->fetchAll(
    $connection->select()->from($log, ['id', 'level', 'message', 'extra'])
        ->where('extra LIKE ?', '%' . $qliroOrderId . '%')
        ->order('id')
);

// A request line names the order in its body, the lines of its outcome follow it
$calls = [];
$criticals = 0;
$open = null;

foreach ($rows as $row) {
    $message = (string)$row['message'];

    if (str_contains($message, '>>> POST checkout/adminapi/v2/updatemerchantreference')) {
        $calls[] = ['outcome' => 'pending'];
        $open = array_key_last($calls);
    } elseif ($open !== null && str_contains($message, '<<< Result')) {
        $calls[$open]['outcome'] = 'ok';
        $open = null;
    } elseif ($open !== null && str_contains($message, '<<< Exception')) {
        $extra = (string)$row['extra'];
        $calls[$open]['outcome'] = 'refused';
        $calls[$open]['level'] = strtolower((string)$row['level']);
        $calls[$open]['alreadySet'] = str_contains($extra, 'reference set to');
        $open = null;
    }

    if (str_contains($message, 'Failed to set the Magento order reference')) {
        $criticals++;
    }
}

echo json_encode([
    'orderId' => (int)$order->getId(),
    'qliroOrderId' => (int)$qliroOrderId,
    'state' => $order->getState(),
    'mark' => (bool)$payment->getAdditionalInformation($key),
    'calls' => $calls,
    'criticals' => $criticals,
], JSON_PRETTY_PRINT) . PHP_EOL;
