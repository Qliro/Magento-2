<?php
/**
 * What tests/callback-credentials.spec.ts needs inside the Magento container.
 *
 * Usage:
 *   php var/callback-credentials.php backup              keeps the store's key, secret and replaced pairs in var/
 *   php var/callback-credentials.php restore             puts them back and clears the refusal rate limit
 *   php var/callback-credentials.php save --key=<k> --secret=<s> [--type=<sandbox|prod>]
 *                                                        saves the Qliro API section as the admin does,
 *                                                        an empty secret is sent as the obscured field is
 *   php var/callback-credentials.php token               a callback token as an order created now gets
 *   php var/callback-credentials.php replaced            how many replaced pairs the default scope keeps
 *   php var/callback-credentials.php mark                the last qliroone_log id
 *   php var/callback-credentials.php log --after=<id>    the security lines written since
 *   php var/callback-credentials.php unthrottle          clears the refusal rate limit
 *   php var/callback-credentials.php push --order=<id> --transaction=<id> --token=<t> --url=<store url>
 *                                                        Qliro's transaction status callback for that capture
 *
 * Prints one JSON object.
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;

require '/var/www/html/app/bootstrap.php';

$command = $argv[1] ?? '';
$options = [];

foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([a-z]+)=(.*)$/s', $argument, $match)) {
        $options[$match[1]] = $match[2];
    }
}

$bootstrap = Bootstrap::create(BP, []);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

$backupFile = BP . '/var/e2e-callback-credentials.json';
$connection = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
$configTable = $connection->getTableName('core_config_data');
$paths = [
    'payment/qliroone/qliro_api/merchant_api_key',
    'payment/qliroone/qliro_api/merchant_api_secret',
    \Qliro\QliroOne\Model\Security\PreviousCredentials::PATH,
    'payment/qliroone/callback/previous_credentials_days',
    'payment/qliroone/qliro_api/type',
];

$unthrottle = static function () use ($om): void {
    $om->get(\Magento\Framework\App\CacheInterface::class)->clean([\Qliro\QliroOne\Model\Security\TokenLogThrottle::CACHE_TAG]);
};

$cleanConfig = static function () use ($om): void {
    $om->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class)->reinit();
    $om->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('config');
};

$backup = static function () use ($connection, $configTable, $paths, $backupFile): void {
    if (!is_file($backupFile)) {
        $rows = $connection->fetchAll($connection->select()->from($configTable)->where('path IN (?)', $paths));
        file_put_contents($backupFile, json_encode($rows));
    }
};

switch ($command) {
    case 'backup':
        $backup();
        $result = ['backup' => true];
        break;

    case 'restore':
        $rows = json_decode((string)@file_get_contents($backupFile), true);
        if (is_array($rows)) {
            $connection->delete($configTable, ['path IN (?)' => $paths]);
            foreach ($rows as $row) {
                unset($row['config_id']);
                $connection->insert($configTable, $row);
            }
            unlink($backupFile);
        }
        $cleanConfig();
        $unthrottle();
        $result = ['restored' => is_array($rows)];
        break;

    case 'save':
        // Never change the store's credentials without a way back
        $backup();
        // The admin's own save, so the field backend models run as they do for a merchant
        // In the order of the admin form, the API type first
        $fields = isset($options['type']) ? ['type' => ['value' => $options['type']]] : [];
        $fields['merchant_api_key'] = ['value' => (string)($options['key'] ?? '')];
        $fields['merchant_api_secret'] = ['value' => ($options['secret'] ?? '') === '' ? '******' : $options['secret']];
        $config = $om->create(\Magento\Config\Model\Config::class);
        $config->setSection('payment');
        $config->setGroups(['qliroone' => ['groups' => ['qliro_api' => ['fields' => $fields]]]]);
        $config->save();
        $cleanConfig();
        $result = ['saved' => true];
        break;

    case 'days':
        $backup();
        $connection->insertOnDuplicate(
            $configTable,
            ['scope' => 'default', 'scope_id' => 0, 'path' => 'payment/qliroone/callback/previous_credentials_days', 'value' => (string)$options['value']],
            ['value']
        );
        $cleanConfig();
        $result = ['days' => (int)$options['value']];
        break;

    case 'token':
        $result = ['token' => $om->get(\Qliro\QliroOne\Model\Security\CallbackToken::class)->getToken()];
        break;

    case 'replaced':
        $stored = (string)$connection->fetchOne(
            $connection->select()->from($configTable, 'value')
                ->where('path = ?', \Qliro\QliroOne\Model\Security\PreviousCredentials::PATH)
                ->where('scope = ?', 'default')
        );
        $pairs = $stored === ''
            ? []
            : json_decode($om->get(\Magento\Framework\Encryption\EncryptorInterface::class)->decrypt($stored), true);
        $result = ['count' => count((array)$pairs), 'encrypted' => $stored === '' || !str_starts_with($stored, '[')];
        break;

    case 'mark':
        $result = ['id' => (int)$connection->fetchOne(
            $connection->select()->from($connection->getTableName('qliroone_log'), 'MAX(id)')
        )];
        break;

    case 'log':
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($connection->getTableName('qliroone_log'), ['level', 'message', 'extra'])
                ->where('id > ?', (int)($options['after'] ?? 0))
                ->where('tags LIKE ?', '%security%')
                ->order('id ASC')
        );
        $result = ['lines' => $rows];
        break;

    case 'unthrottle':
        $unthrottle();
        $result = ['unthrottled' => true];
        break;

    case 'push':
        $order = $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->get((int)$options['order']);
        $link = $om->get(\Qliro\QliroOne\Api\LinkRepositoryInterface::class)->getByOrderId((int)$order->getId(), false);
        $curl = curl_init(sprintf(
            '%s/checkout/qliro_callback/transactionStatus?token=%s',
            rtrim((string)$options['url'], '/'),
            urlencode((string)$options['token'])
        ));
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'OrderId' => (int)$link->getQliroOrderId(),
                'MerchantReference' => $link->getReference(),
                'PaymentTransactionId' => (int)$options['transaction'],
                'Status' => 'Success',
                'PaymentType' => 'Capture',
                'Amount' => 34.0,
                'Currency' => $order->getOrderCurrencyCode(),
            ]),
        ]);
        $response = curl_exec($curl);
        $result = [
            'status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            'response' => json_decode((string)$response, true),
        ];
        break;

    default:
        fwrite(STDERR, "unknown command\n");
        exit(1);
}

echo json_encode($result), "\n";
