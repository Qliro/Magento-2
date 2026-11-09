<?php
/**
 * What tests/capture-relink.spec.ts needs inside the Magento container.
 *
 * Usage:
 *   php var/capture-relink.php state --order=<entity id>   invoices, their transactions and what Qliro holds
 *   php var/capture-relink.php break --order=<entity id>   puts every invoice of the order on the
 *       preauthorization, as an invoice Qliro refused as already shipped was recorded before 1.8.2:
 *       the invoices share one capture transaction under that id, without a captured amount, and
 *       the status row of the refusal carries the id
 *
 * Prints one JSON object.
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;

require '/var/www/html/app/bootstrap.php';

$command = $argv[1] ?? '';
$options = [];

foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([a-z]+)=(.*)$/', $argument, $match)) {
        $options[$match[1]] = $match[2];
    }
}

$om = Bootstrap::create(BP, [])->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

$resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();
$orderId = (int)($options['order'] ?? 0);
$order = $om->create(\Magento\Sales\Model\Order::class)->load($orderId);
$paymentId = (int)$order->getPayment()->getId();
$qliroOrderId = (int)$om->get(\Qliro\QliroOne\Api\LinkRepositoryInterface::class)
    ->getByOrderId($orderId, false)
    ->getQliroOrderId();

$atQliro = array_map(
    static fn ($transaction) => [
        'id' => (int)$transaction->getPaymentTransactionId(),
        'type' => (string)$transaction->getType(),
        'status' => (string)$transaction->getStatus(),
        'amount' => (float)$transaction->getAmount(),
    ],
    (array)$om->get(\Qliro\QliroOne\Api\Client\OrderManagementInterface::class)
        ->getOrder($qliroOrderId)
        ->getPaymentTransactions()
);

$invoices = static fn () => $connection->fetchAll(
    $connection->select()
        ->from($resource->getTableName('sales_invoice'), ['entity_id', 'increment_id', 'grand_total', 'transaction_id'])
        ->where('order_id = ?', $orderId)
        ->order('entity_id ASC')
);

switch ($command) {
    case 'state':
        $transactions = $connection->fetchAll(
            $connection->select()
                ->from($resource->getTableName('sales_payment_transaction'), [
                    'txn_id', 'parent_txn_id', 'txn_type', 'additional_information',
                ])
                ->where('payment_id = ?', $paymentId)
                ->order('transaction_id ASC')
        );
        $comments = [];

        foreach ($order->getStatusHistoryCollection() as $entry) {
            $comments[] = (string)$entry->getComment();
        }

        echo json_encode([
            'invoices' => array_map(static fn ($row) => [
                'id' => (int)$row['entity_id'],
                'incrementId' => $row['increment_id'],
                'grandTotal' => (float)$row['grand_total'],
                'transactionId' => (string)$row['transaction_id'],
            ], $invoices()),
            'transactions' => array_map(static function ($row) {
                $info = json_decode((string)$row['additional_information'], true) ?: [];

                return [
                    'txnId' => $row['txn_id'],
                    'parentTxnId' => $row['parent_txn_id'],
                    'type' => $row['txn_type'],
                    'capturedAmount' => isset($info['captured_amount']) ? (float)$info['captured_amount'] : null,
                    'refundedAmount' => isset($info['refunded_amount']) ? (float)$info['refunded_amount'] : null,
                ];
            }, $transactions),
            'atQliro' => $atQliro,
            'comments' => $comments,
            'creditMemos' => (int)$order->getCreditmemosCollection()->getSize(),
        ]) . PHP_EOL;
        break;

    case 'break':
        $preauthorization = current(array_filter($atQliro, static fn ($row) => $row['type'] === 'Preauthorization'));

        if (!$preauthorization) {
            fwrite(STDERR, "Qliro holds no preauthorization for the order\n");
            exit(1);
        }

        $table = $resource->getTableName('sales_payment_transaction');
        $kept = false;

        foreach ($invoices() as $invoice) {
            $capture = $connection->fetchRow(
                $connection->select()->from($table)
                    ->where('payment_id = ?', $paymentId)
                    ->where('txn_id = ?', $invoice['transaction_id'])
                    ->where('txn_type = ?', 'capture')
            );

            // Magento keeps one transaction per id, the invoices share the first one
            if ($capture && !$kept) {
                $info = json_decode((string)$capture['additional_information'], true) ?: [];
                unset($info['captured_amount'], $info['captured_currency']);
                $connection->update(
                    $table,
                    ['txn_id' => (string)$preauthorization['id'], 'additional_information' => json_encode($info)],
                    ['transaction_id = ?' => (int)$capture['transaction_id']]
                );
                $kept = true;
            } elseif ($capture) {
                $connection->delete($table, ['transaction_id = ?' => (int)$capture['transaction_id']]);
            }

            $connection->update(
                $resource->getTableName('sales_invoice'),
                ['transaction_id' => (string)$preauthorization['id']],
                ['entity_id = ?' => (int)$invoice['entity_id']]
            );
        }

        $connection->insert($resource->getTableName('qliroone_om_status'), [
            'record_type' => 'payment',
            'record_id' => $paymentId,
            'transaction_id' => $preauthorization['id'],
            'transaction_status' => 'Created',
            'notification_status' => 'handled',
            'message' => 'Capture already registered at Qliro (invoice)',
            'qliro_order_id' => $qliroOrderId,
        ]);

        echo json_encode(['preauthorization' => $preauthorization['id']]) . PHP_EOL;
        break;

    default:
        fwrite(STDERR, "Unknown command $command\n");
        exit(1);
}
