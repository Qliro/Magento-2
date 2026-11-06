<?php
/**
 * What tests/capture-retry.spec.ts needs inside the Magento container.
 *
 * Usage:
 *   php var/capture-retry.php captures --order=<entity id>   the captures the order at Qliro holds
 *   php var/capture-retry.php notify --order=<entity id> --transaction=<id> --amount=<amount> --url=<store url>
 *                                                            sends Qliro's Success notification of a capture
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

$order = $om->create(\Magento\Sales\Model\Order::class)->load((int)($options['order'] ?? 0));
$qliroOrderId = (int)$om->get(\Qliro\QliroOne\Api\LinkRepositoryInterface::class)
    ->getByOrderId((int)$order->getId(), false)
    ->getQliroOrderId();

switch ($command) {
    case 'captures':
        $qliroOrder = $om->get(\Qliro\QliroOne\Api\Client\OrderManagementInterface::class)
            ->getOrder($qliroOrderId, $order->getStoreId(), \Qliro\QliroOne\Model\Config::API_PROFILE_BACKGROUND);
        $captures = [];

        foreach ($qliroOrder->getPaymentTransactions() ?: [] as $transaction) {
            if ($transaction->getType() === 'Capture') {
                $captures[] = [
                    'transactionId' => (int)$transaction->getPaymentTransactionId(),
                    'status' => $transaction->getStatus(),
                    'amount' => (float)$transaction->getAmount(),
                ];
            }
        }

        $result = ['qliroOrderId' => $qliroOrderId, 'captures' => $captures];
        break;

    case 'notify':
        $body = [
            'OrderId' => $qliroOrderId,
            'MerchantReference' => $order->getIncrementId(),
            'PaymentTransactionId' => (int)$options['transaction'],
            'Status' => 'Success',
            'PaymentType' => 'Capture',
            'Amount' => (float)$options['amount'],
            'Currency' => $order->getOrderCurrencyCode(),
        ];
        $token = $om->get(\Qliro\QliroOne\Model\Security\CallbackToken::class)->getToken();

        $curl = curl_init(sprintf(
            '%s/checkout/qliro_callback/transactionStatus?token=%s',
            rtrim((string)$options['url'], '/'),
            urlencode($token)
        ));
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
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
