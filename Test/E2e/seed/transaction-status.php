<?php
/**
 * Seeds what a transaction status push meets in the store.
 *
 * Usage, inside the Magento container:
 *   php var/transaction-status.php token
 *       the callback token
 *   php var/transaction-status.php link [--placed] [--inactive]
 *       a guest quote with its link, with --placed an order placed from it, with --inactive the link
 *       deactivated afterwards as a cancel does
 *   php var/transaction-status.php fail --txn=ID | unfail --txn=ID
 *       makes storing a status of that transaction throw, and stops it
 *   php var/transaction-status.php read --qliro=ID
 *       the stored statuses and the module's log lines that name the Qliro order
 *
 * Prints one JSON object.
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;

require '/var/www/html/app/bootstrap.php';

$command = $argv[1] ?? '';
$options = [];

foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([a-z]+)(?:=(.*))?$/', $argument, $match)) {
        $options[$match[1]] = $match[2] ?? true;
    }
}

$bootstrap = Bootstrap::create(BP, []);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');

$storeManager = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
$store = $storeManager->getStore(1);
$storeManager->setCurrentStore($store);

$resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();
$token = $om->get(\Qliro\QliroOne\Model\Security\CallbackToken::class)->getToken();
$trigger = static fn (string $txn): string => 'e2e_fail_om_status_' . preg_replace('/\D/', '', $txn);

$out = static function (array $data): void {
    echo json_encode($data, JSON_PRETTY_PRINT) . PHP_EOL;
};

switch ($command) {
    case 'token':
        $out(['callbackToken' => $token]);
        break;

    case 'link':
        $quoteRepository = $om->get(\Magento\Quote\Api\CartRepositoryInterface::class);
        $quote = $om->create(\Magento\Quote\Model\Quote::class);
        $quote->setStore($store);
        $quote->setCurrency();
        $quote->addProduct($om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->get('QLIRO-E2E-PRODUCT'), 1);

        $address = [
            'firstname' => 'Qliro', 'lastname' => 'Tester', 'street' => ['Testgatan 1'],
            'city' => 'Stockholm', 'country_id' => 'SE', 'postcode' => '11122',
            'telephone' => '0700000000', 'email' => 'qliro.e2e@example.com',
        ];
        $region = $om->create(\Magento\Directory\Model\Region::class)->loadByName('Stockholms län', 'SE');
        if ($region->getId()) {
            $address += ['region_id' => (int)$region->getId(), 'region' => $region->getName()];
        }
        $quote->getBillingAddress()->addData($address);
        $quote->getShippingAddress()->addData($address);
        $quote->getShippingAddress()->setCollectShippingRates(true)->setShippingMethod('flatrate_flatrate');
        $quote->setCheckoutMethod('guest')->setCustomerIsGuest(true)->setCustomerEmail($address['email']);
        $quote->collectTotals();
        $quoteRepository->save($quote);

        $quote = $quoteRepository->get((int)$quote->getId());
        $quote->getPayment()->importData(['method' => 'qliroone']);
        $quote->collectTotals();
        $quoteRepository->save($quote);

        $om->get(\Magento\Quote\Model\QuoteIdMaskFactory::class)->create()
            ->setQuoteId((int)$quote->getId())->save();

        // Apart from the ranges the other seeders take from the same quote id sequence
        $qliroOrderId = 600000000 + (int)$quote->getId() % 100000000;

        $link = $om->create(\Qliro\QliroOne\Model\Link::class);
        $link->setQuoteId((int)$quote->getId())
            ->setQliroOrderId($qliroOrderId)
            ->setReference('e2e-txn-' . $quote->getId())
            ->setQliroOrderStatus('InProcess')
            ->setQuoteSnapshot('e2e')
            ->setIsActive(1);
        $om->get(\Qliro\QliroOne\Api\LinkRepositoryInterface::class)->save($link);

        $orderId = 0;
        if (isset($options['placed'])) {
            $containerMapper = $om->get(\Qliro\QliroOne\Model\ContainerMapper::class);
            $orderItems = [];
            foreach ($om->create(\Qliro\QliroOne\Model\QliroOrder\Builder\OrderItemsBuilder::class)
                ->setQuote($quote)->create() as $item) {
                $orderItems[] = $containerMapper->toArray($item);
            }

            $total = 0.0;
            foreach ($orderItems as $item) {
                $total += $item['PricePerItemIncVat'] * $item['Quantity'];
            }

            $qliroOrder = $containerMapper->fromArray(
                [
                    'OrderId' => $qliroOrderId,
                    'MerchantReference' => $link->getReference(),
                    'CustomerCheckoutStatus' => 'Completed',
                    'TotalPrice' => $total,
                    'Currency' => $quote->getQuoteCurrencyCode(),
                    'Country' => 'SE',
                    'PaymentMethod' => ['PaymentMethodName' => 'QLIRO_CARD', 'PaymentTypeCode' => 'CARD'],
                    'OrderItems' => $orderItems,
                ],
                \Qliro\QliroOne\Api\Data\QliroOrderInterface::class
            );

            $placeOrder = $om->create(\Qliro\QliroOne\Model\Management\PlaceOrder::class);
            $placeOrder->setQuote($quote);
            $orderId = (int)$placeOrder->execute($qliroOrder, \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT)->getId();
        }

        if (isset($options['inactive'])) {
            $connection->update(
                $resource->getTableName('qliroone_link'),
                ['is_active' => 0],
                ['qliro_order_id = ?' => $qliroOrderId]
            );
        }

        $out(['qliroOrderId' => $qliroOrderId, 'orderId' => $orderId, 'callbackToken' => $token]);
        break;

    case 'fail':
        if (!ctype_digit((string)($options['txn'] ?? ''))) {
            fwrite(STDERR, "fail needs --txn\n");
            exit(1);
        }
        $name = $trigger((string)$options['txn']);
        $connection->query("DROP TRIGGER IF EXISTS {$name}");
        // The adapter refuses a body with semicolons, PDO takes it
        $connection->getConnection()->exec(sprintf(
            "CREATE TRIGGER %s BEFORE INSERT ON %s FOR EACH ROW BEGIN IF NEW.transaction_id = %s THEN "
            . "SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'e2e: storing this status fails'; END IF; END",
            $name,
            $resource->getTableName('qliroone_om_status'),
            $connection->quote((string)$options['txn'])
        ));
        $out(['trigger' => $name]);
        break;

    case 'unfail':
        if (!ctype_digit((string)($options['txn'] ?? ''))) {
            fwrite(STDERR, "unfail needs --txn\n");
            exit(1);
        }
        $name = $trigger((string)$options['txn']);
        $connection->query("DROP TRIGGER IF EXISTS {$name}");
        $out(['trigger' => $name]);
        break;

    case 'read':
        $qliroOrderId = (string)$options['qliro'];
        $statuses = $connection->fetchCol(
            $connection->select()
                ->from($resource->getTableName('qliroone_om_status'), ['transaction_status'])
                ->where('qliro_order_id = ?', $qliroOrderId)
        );
        $lines = $connection->fetchAll(
            $connection->select()
                ->from($resource->getTableName('qliroone_log'), ['level', 'message'])
                ->where('message LIKE ? OR extra LIKE ?', '%' . $qliroOrderId . '%')
                ->order('id ASC')
        );

        $out(['statuses' => $statuses, 'log' => $lines]);
        break;

    default:
        fwrite(STDERR, "Unknown command, see the usage at the top of this file\n");
        exit(1);
}
