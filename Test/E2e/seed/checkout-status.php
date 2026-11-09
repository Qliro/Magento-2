<?php
/**
 * Seeds what a checkout status push meets in the store.
 *
 * Usage, inside the Magento container:
 *   php var/checkout-status.php token
 *       the callback token
 *   php var/checkout-status.php placed
 *       a guest quote with its link and an order placed from a completed Qliro order
 *   php var/checkout-status.php stock --sku=SKU --qty=N
 *       sets the stock of a product, 0 takes it out of stock
 *   php var/checkout-status.php read --qliro=ID
 *       the order of the Qliro order and the module's log lines that name it
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

$bootstrap = Bootstrap::create(BP, []);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');

$storeManager = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
$store = $storeManager->getStore(1);
$storeManager->setCurrentStore($store);

$quoteRepository = $om->get(\Magento\Quote\Api\CartRepositoryInterface::class);
$linkRepository = $om->get(\Qliro\QliroOne\Api\LinkRepositoryInterface::class);
$containerMapper = $om->get(\Qliro\QliroOne\Model\ContainerMapper::class);
$productRepository = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
$resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();
$token = $om->get(\Qliro\QliroOne\Model\Security\CallbackToken::class)->getToken();

$out = static function (array $data): void {
    echo json_encode($data, JSON_PRETTY_PRINT) . PHP_EOL;
};

switch ($command) {
    case 'token':
        $out(['callbackToken' => $token]);
        break;

    case 'placed':
        $quote = $om->create(\Magento\Quote\Model\Quote::class);
        $quote->setStore($store);
        $quote->setCurrency();
        $quote->addProduct($productRepository->get('QLIRO-E2E-PRODUCT'), 1);

        $address = [
            'firstname' => 'Qliro', 'lastname' => 'Tester', 'street' => ['Testgatan 1'],
            'city' => 'Stockholm', 'country_id' => 'SE', 'postcode' => '11122',
            'telephone' => '0700000000', 'email' => 'qliro.e2e@example.com',
        ];
        // A store may require a region for Sweden
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
        $qliroOrderId = 700000000 + (int)$quote->getId();

        $link = $om->create(\Qliro\QliroOne\Model\Link::class);
        $link->setQuoteId((int)$quote->getId())
            ->setQliroOrderId($qliroOrderId)
            ->setReference('e2e-status-' . $quote->getId())
            ->setQliroOrderStatus('InProcess')
            ->setQuoteSnapshot('e2e')
            ->setIsActive(1);
        $linkRepository->save($link);

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
        $order = $placeOrder->execute($qliroOrder, \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);

        $out([
            'qliroOrderId' => $qliroOrderId,
            'orderId' => (int)$order->getId(),
            'callbackToken' => $token,
        ]);
        break;

    case 'stock':
        if (!isset($options['qty'], $options['sku'])) {
            fwrite(STDERR, "stock needs --sku and --qty\n");
            exit(1);
        }
        $qty = (int)$options['qty'];

        // The sandbox seeder creates it in stock on its first run
        try {
            $productRepository->get($options['sku']);
        } catch (\Magento\Framework\Exception\NoSuchEntityException $exception) {
            $out(['sku' => $options['sku'], 'qty' => null]);
            break;
        }

        $item = $om->get(\Magento\CatalogInventory\Api\StockRegistryInterface::class)
            ->getStockItemBySku($options['sku']);
        $item->setQty($qty)->setIsInStock($qty > 0);
        $om->get(\Magento\CatalogInventory\Api\StockRegistryInterface::class)
            ->updateStockItemBySku($options['sku'], $item);

        // A store on MSI reads the source item
        if ($om->get(\Magento\Framework\Module\Manager::class)->isEnabled('Magento_InventoryApi')) {
            $sourceItem = $om->create(\Magento\InventoryApi\Api\Data\SourceItemInterface::class);
            $sourceItem->setSku($options['sku']);
            $sourceItem->setSourceCode('default');
            $sourceItem->setQuantity($qty);
            $sourceItem->setStatus($qty > 0 ? 1 : 0);
            $om->get(\Magento\InventoryApi\Api\SourceItemsSaveInterface::class)->execute([$sourceItem]);
        }

        // Stock indexers on schedule never run without cron
        $productId = (int)$productRepository->get($options['sku'])->getId();
        $om->get(\Magento\Framework\Indexer\IndexerRegistry::class)->get('cataloginventory_stock')
            ->reindexRow($productId);

        $out(['sku' => $options['sku'], 'qty' => $qty]);
        break;

    case 'read':
        $qliroOrderId = (string)$options['qliro'];
        $link = $linkRepository->getByQliroOrderId($qliroOrderId, false);
        $lines = $connection->fetchAll(
            $connection->select()
                ->from($resource->getTableName('qliroone_log'), ['level', 'message', 'extra'])
                ->where('extra LIKE ?', '%' . $qliroOrderId . '%')
                ->order('id ASC')
        );

        $out([
            'orderId' => (int)$link->getOrderId(),
            'status' => (string)$link->getQliroOrderStatus(),
            'log' => array_map(
                static fn (array $line) => [
                    'level' => $line['level'],
                    'message' => $line['message'],
                    'extra' => json_decode((string)$line['extra'], true),
                ],
                $lines
            ),
        ]);
        break;

    default:
        fwrite(STDERR, "Unknown command, see the usage at the top of this file\n");
        exit(1);
}
