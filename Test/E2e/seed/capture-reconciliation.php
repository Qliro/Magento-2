<?php
/**
 * What tests/capture-confirmed-early.spec.ts needs inside the Magento container.
 *
 * Usage:
 *   php var/capture-reconciliation.php state --order=<entity id>   invoices, state and the OM rows
 *   php var/capture-reconciliation.php cron                        runs the reconciliation job once
 *   php var/capture-reconciliation.php age --order=<entity id> --days=4
 *                                                                  moves the order's OM rows back in time
 *   php var/capture-reconciliation.php resend --order=<entity id> --url=<store url>
 *                                                                  sends the stored early callback again
 *
 * Prints one JSON object.
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;

require '/var/www/html/app/bootstrap.php';

$command = $argv[1] ?? '';
$options = [];

// getopt() stops at the command, so the options after it are read here
foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $argument, $match)) {
        $options[$match[1]] = $match[2];
    }
}

$bootstrap = Bootstrap::create(BP, []);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('crontab');

$resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();
$orderId = (int)($options['order'] ?? 0);

$qliroOrderId = static function () use ($om, $orderId): int {
    return (int)$om->get(\Qliro\QliroOne\Api\LinkRepositoryInterface::class)
        ->getByOrderId($orderId, false)
        ->getQliroOrderId();
};

switch ($command) {
    case 'state':
        $order = $om->create(\Magento\Sales\Model\Order::class)->load($orderId);
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($resource->getTableName('qliroone_om_status'), [
                    'transaction_id', 'record_type', 'record_id', 'transaction_status', 'notification_status',
                ])
                ->where('qliro_order_id = ?', $qliroOrderId())
                ->order('id ASC')
        );
        $result = [
            'state' => $order->getState(),
            'status' => $order->getStatus(),
            'shipments' => (int)$order->getShipmentsCollection()->getSize(),
            'invoices' => array_map(
                static fn ($invoice) => [
                    'grandTotal' => (float)$invoice->getGrandTotal(),
                    'transactionId' => $invoice->getTransactionId(),
                    'state' => (int)$invoice->getState(),
                ],
                array_values($order->getInvoiceCollection()->getItems())
            ),
            'totalPaid' => (float)$order->getTotalPaid(),
            'omRows' => $rows,
        ];
        break;

    case 'cron':
        $om->get(\Qliro\QliroOne\Cron\ReconcileShipmentCaptures::class)->execute();
        $result = ['ran' => true];
        break;

    case 'age':
        $days = (int)($options['days'] ?? 4);
        $updated = $connection->update(
            $resource->getTableName('qliroone_om_status'),
            ['date' => new \Zend_Db_Expr(sprintf('date - INTERVAL %d DAY', $days))],
            ['qliro_order_id = ?' => $qliroOrderId()]
        );
        $result = ['updated' => $updated];
        break;

    case 'resend':
        $order = $om->create(\Magento\Sales\Model\Order::class)->load($orderId);
        $file = sprintf('%s/var/qliro-e2e/early-callback-%s.result.json', BP, $order->getIncrementId());
        $body = json_decode((string)file_get_contents($file), true)['request'];
        $token = $om->get(\Qliro\QliroOne\Model\Security\CallbackToken::class)->getToken();

        $curl = curl_init(sprintf(
            '%s/checkout/qliro_callback/transactionStatus?token=%s',
            rtrim((string)$options['url'], '/'),
            urlencode($token)
        ));
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        $response = curl_exec($curl);
        $result = [
            'status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            'response' => json_decode((string)$response, true),
        ];
        break;

    default:
        fwrite(STDERR, "Unknown command '$command'\n");
        exit(1);
}

echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
