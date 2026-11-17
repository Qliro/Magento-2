<?php
/**
 * A buyer whose names, city and phone Magento 2.4.8 or 2.4.9 refuses as Qliro sends them.
 *
 * Usage, inside the Magento container:
 *   php var/seed-address-text.php quote
 *       a guest quote with its link, the buyer as the checkout's customer update brings them, and
 *       the validate callback for it
 *   php var/seed-address-text.php place --quote=ID
 *       places the order from a Qliro order carrying the same buyer
 *   php var/seed-address-text.php read --order=ID
 *       the order's billing and shipping address
 *
 * Prints one JSON object.
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;

require '/var/www/html/app/bootstrap.php';

const SKUS = ['QLIRO-E2E-PRODUCT' => 1];
const SHIPPING = 'flatrate_flatrate';

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

$out = static function (array $data): void {
    echo json_encode($data, JSON_PRETTY_PRINT) . PHP_EOL;
};

$product = static function (string $sku) use ($om) {
    $repository = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);

    try {
        return $repository->get($sku);
    } catch (\Magento\Framework\Exception\NoSuchEntityException $exception) {
        $product = $om->create(\Magento\Catalog\Model\Product::class);
        $product->setSku($sku)
            ->setName('Qliro e2e ' . strtolower($sku))
            ->setUrlKey(strtolower($sku))
            ->setAttributeSetId(4)
            ->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
            ->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH)
            ->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_SIMPLE)
            ->setPrice(34.00)
            ->setWebsiteIds([1])
            ->setStockData(['use_config_manage_stock' => 1, 'qty' => 1000, 'is_in_stock' => 1]);

        return $repository->save($product);
    }
};

// The lines the module sends Qliro for the quote, which is what Qliro reserves
$qliroLines = static function ($quote) use ($om, $containerMapper): array {
    $lines = [];

    foreach ($om->create(\Qliro\QliroOne\Model\QliroOrder\Builder\OrderItemsBuilder::class)
        ->setQuote($quote)->create() as $item) {
        $lines[] = $containerMapper->toArray($item);
    }

    $address = $quote->getShippingAddress();
    $lines[] = [
        'MerchantReference' => SHIPPING, 'Description' => 'Flat Rate', 'Type' => QliroOrderItemInterface::TYPE_SHIPPING,
        'Quantity' => 1,
        'PricePerItemIncVat' => (float)$address->getShippingInclTax(),
        'PricePerItemExVat' => (float)$address->getShippingAmount(),
    ];

    return $lines;
};

$qliroAddress = [
    'FirstName' => 'Anna/Maria', 'LastName' => 'Hans (Jr)', 'Street' => 'Testgatan 1',
    'PostalCode' => '11122', 'City' => 'St. Gallen (Ost) 2', 'CountryCode' => 'SE',
];
$qliroCustomer = [
    'Email' => 'qliro.e2e@example.com', 'MobileNumber' => '+46 70 123 45 67 89 10',
    'FirstName' => 'Anna/Maria', 'LastName' => 'Hans (Jr)', 'Address' => $qliroAddress,
];

switch ($command) {
    case 'quote':
        $quote = $om->create(\Magento\Quote\Model\Quote::class);
        $quote->setStore($store);
        $quote->setCurrency();

        foreach (SKUS as $sku => $qty) {
            $quote->addProduct($product($sku), $qty);
        }

        $address = [
            'firstname' => 'Qliro', 'lastname' => 'Tester', 'street' => ['Testgatan 1'],
            'city' => 'Stockholm', 'country_id' => 'SE', 'postcode' => '11122',
            'telephone' => '0700000000', 'email' => 'qliro.e2e@example.com',
        ];
        $quote->getBillingAddress()->addData($address);
        $quote->getShippingAddress()->addData($address);
        $quote->getShippingAddress()->setCollectShippingRates(true)->setShippingMethod(SHIPPING);
        $quote->setCheckoutMethod('guest')->setCustomerIsGuest(true)->setCustomerEmail($address['email']);
        $quote->collectTotals();
        $quoteRepository->save($quote);

        $quote = $quoteRepository->get((int)$quote->getId());
        $quote->getPayment()->importData(['method' => 'qliroone']);
        $quote->collectTotals();
        $quoteRepository->save($quote);
        $quote = $quoteRepository->get((int)$quote->getId());

        // A guest cart is placed through the checkout API by its masked id
        $om->get(\Magento\Quote\Model\QuoteIdMaskFactory::class)->create()
            ->setQuoteId((int)$quote->getId())->save();

        // What the checkout's customer update does with the buyer Qliro identified
        $om->create(\Qliro\QliroOne\Model\Management\Quote::class)->setQuote($quote)->updateCustomer($qliroCustomer);
        $quote = $quoteRepository->get((int)$quote->getId());

        // Apart from the ranges the other seeders use, they share the quote id sequence
        $qliroOrderId = 810000000 + (int)$quote->getId();
        $reference = 'e2e-address-' . $quote->getId();

        $link = $om->create(\Qliro\QliroOne\Model\Link::class);
        $link->setQuoteId((int)$quote->getId())
            ->setQliroOrderId($qliroOrderId)
            ->setReference($reference)
            ->setQliroOrderStatus('InProcess')
            ->setQuoteSnapshot('e2e')
            ->setIsActive(1);
        $linkRepository->save($link);

        $out([
            'quoteId' => (int)$quote->getId(),
            'callbackToken' => $om->get(\Qliro\QliroOne\Model\Security\CallbackToken::class)->getToken(),
            'validate' => [
                'OrderId' => $qliroOrderId,
                'MerchantReference' => $reference,
                'Currency' => $quote->getQuoteCurrencyCode(),
                'Customer' => $qliroCustomer,
                'ShippingAddress' => $qliroAddress,
                'SelectedShippingMethod' => SHIPPING,
                'OrderItems' => $qliroLines($quote),
            ],
        ]);
        break;

    case 'place':
        $quote = $quoteRepository->get((int)$options['quote']);
        $link = $linkRepository->getByQuoteId((int)$quote->getId());
        $orderItems = $qliroLines($quote);

        $qliroTotal = 0.0;
        foreach ($orderItems as $item) {
            $qliroTotal += $item['PricePerItemIncVat'] * $item['Quantity'];
        }

        $qliroOrder = $containerMapper->fromArray(
            [
                'OrderId' => $link->getQliroOrderId(),
                'MerchantReference' => $link->getReference(),
                'CustomerCheckoutStatus' => 'Completed',
                'TotalPrice' => $qliroTotal,
                'Currency' => $quote->getQuoteCurrencyCode(),
                'Country' => 'SE',
                'Customer' => $qliroCustomer,
                'BillingAddress' => $qliroAddress,
                'ShippingAddress' => $qliroAddress,
                'PaymentMethod' => ['PaymentMethodName' => 'QLIRO_CARD', 'PaymentTypeCode' => 'CARD'],
                'OrderItems' => $orderItems,
            ],
            \Qliro\QliroOne\Api\Data\QliroOrderInterface::class
        );

        $placeOrder = $om->create(\Qliro\QliroOne\Model\Management\PlaceOrder::class);
        $placeOrder->setQuote($quote);
        $order = $placeOrder->execute($qliroOrder, \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);

        $out(['orderId' => (int)$order->getId(), 'incrementId' => $order->getIncrementId()]);
        break;

    case 'read':
        $order = $om->create(\Magento\Sales\Model\Order::class)->load((int)$options['order']);
        $fields = static fn ($address) => [
            'firstname' => $address->getFirstname(),
            'lastname' => $address->getLastname(),
            'city' => $address->getCity(),
            'telephone' => $address->getTelephone(),
        ];

        $out([
            'billing' => $fields($order->getBillingAddress()),
            'shipping' => $fields($order->getShippingAddress()),
        ]);
        break;

    default:
        fwrite(STDERR, "Unknown command\n");
        exit(1);
}
