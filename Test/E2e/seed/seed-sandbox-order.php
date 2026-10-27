<?php
/**
 * Places a real order in the Qliro sandbox: a guest quote gets its Qliro order through the module,
 * the buyer's side is completed over Qliro's checkout webapi with invoice and the staging
 * pass-through personal number, so no BankID is involved, and the module places the Magento order
 * the way the success page does.
 *
 * Needs the store pointed at a Qliro test merchant on staging.
 *
 * Usage, inside the Magento container:
 *   php var/seed-sandbox-order.php [--qty=2] [--bundle]
 *
 * `--bundle` buys a bundle priced by its children instead, one at 4.79 and one at 34.00, both
 * Taxable Goods, which the store has to tax for Sweden.
 *
 * Prints one JSON object describing what it created.
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;

require '/var/www/html/app/bootstrap.php';

$options = getopt('', ['qty::', 'bundle']);
$qty = max(1, (int)($options['qty'] ?? 2));
$withBundle = isset($options['bundle']);

$bootstrap = Bootstrap::create(BP, []);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');

/* ------------------------------------------------------------- the product */
$sku = 'QLIRO-E2E-PRODUCT';
$productRepository = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);

try {
    $product = $productRepository->get($sku);
} catch (\Magento\Framework\Exception\NoSuchEntityException $exception) {
    $product = $om->create(\Magento\Catalog\Model\Product::class);
    $product->setSku($sku)
        ->setName('Qliro e2e product')
        ->setAttributeSetId(4)
        ->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
        ->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH)
        ->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_SIMPLE)
        ->setPrice(34.00)
        ->setWebsiteIds([1])
        ->setStockData(['use_config_manage_stock' => 1, 'qty' => 1000, 'is_in_stock' => 1]);
    $product = $productRepository->save($product);
}

/* --------------------------------------------------------------- the quote */
$storeManager = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
$store = $storeManager->getStore(1);
$storeManager->setCurrentStore($store);

$email = 'qliro.e2e+' . time() . '@example.com';
$address = [
    'firstname' => 'Qliro', 'lastname' => 'Tester', 'street' => ['Testgatan 1'],
    'city' => 'Stockholm', 'country_id' => 'SE', 'postcode' => '11122',
    'telephone' => '0701234567', 'email' => $email,
];

$quote = $om->create(\Magento\Quote\Model\Quote::class);
$quote->setStore($store);
$quote->setCurrency();
if ($withBundle) {
    [$bundle, $buyRequest] = qliroE2eBundle($om, $productRepository, $qty);
    $added = $quote->addProduct($bundle, $buyRequest);

    if (is_string($added)) {
        fwrite(STDERR, "The bundle could not be added: $added\n");
        exit(1);
    }
} else {
    $quote->addProduct($product, $qty);
}
$quote->getBillingAddress()->addData($address);
$quote->getShippingAddress()->addData($address);
$quote->getShippingAddress()->setCollectShippingRates(true)->collectShippingRates()
    ->setShippingMethod('flatrate_flatrate');
$quote->setCheckoutMethod('guest')->setCustomerIsGuest(true)->setCustomerEmail($email);
$quote->collectTotals();

if ($withBundle) {
    foreach ($quote->getAllItems() as $item) {
        if ($item->getParentItem() && (float)$item->getTaxPercent() <= 0.0) {
            fwrite(STDERR, "The bundle child {$item->getSku()} carries no VAT, does the store tax Sweden?\n");
            exit(1);
        }
    }
}

$quoteRepository = $om->get(\Magento\Quote\Api\CartRepositoryInterface::class);
$quoteRepository->save($quote);

$quote = $quoteRepository->get((int)$quote->getId());
$quote->getPayment()->importData(['method' => 'qliroone']);
$quote->collectTotals();
$quoteRepository->save($quote);

$om->get(\Magento\Quote\Model\QuoteIdMaskFactory::class)->create()
    ->setQuoteId((int)$quote->getId())->save();

/* --------------------------------------------------------- the Qliro order */
/** @var \Qliro\QliroOne\Model\Management $management */
$management = $om->get(\Qliro\QliroOne\Model\Management::class);
$management->setQuote($quote);
$snippet = (string)$management->getHtmlSnippet();

if (!preg_match('/Bearer\s+([^"\'\s]+)/', $snippet, $match)) {
    fwrite(STDERR, "No webapi token in the snippet, is the store on a Qliro test merchant?\n");
    exit(1);
}

$token = $match[1];
$webapi = \Qliro\QliroOne\Model\Api\Service::QLIRO_SANDBOX_API_URL . '/checkout/webapi/';

$call = static function (string $method, string $path, ?array $body = null) use ($webapi, $token) {
    $curl = curl_init($webapi . $path);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body),
    ]);
    $response = (string)curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

    if ($status < 200 || $status >= 300) {
        fwrite(STDERR, sprintf("%s %s answered %d: %s\n", $method, $path, $status, $response));
        exit(1);
    }

    return $response === '' ? null : json_decode($response, true);
};

$call('POST', 'challenges/completeChallenges', [
    'email' => $email,
    'personalNumber' => '4611068539',
    'mobileNumber' => '0740555089',
]);
$call('POST', 'orders/validateOrder', [
    'paymentMethodName' => 'QLIRO_INVOICE',
    'paymentMethodSubtypeCode' => 'INVOICE',
    'requireIdentityVerification' => false,
]);
$call('POST', 'paymenttransaction/makepayment', [
    'PaymentMethodName' => 'QLIRO_INVOICE',
    'paymentMethodSubtypeCode' => 'INVOICE',
    'ClientTrackingId' => 'qliro-e2e-' . $quote->getId(),
    'affordabilityInformation' => null,
    'paymentProviderExtraDataJson' => null,
]);

for ($attempt = 0; $attempt < 20; $attempt++) {
    $status = $call('GET', 'orders')['customerCheckoutStatus'] ?? null;

    if ($status === 'Completed') {
        break;
    }

    sleep(3);
}

if ($status !== 'Completed') {
    fwrite(STDERR, "The Qliro order did not complete, last status $status\n");
    exit(1);
}

/* -------------------------------------------------------------- the order */
$order = $management->setQuote($quote)->pollPlaceOrder();
$link = $om->get(\Qliro\QliroOne\Api\LinkRepositoryInterface::class)->getByQuoteId((int)$quote->getId(), false);

echo json_encode([
    'orderId' => (int)$order->getId(),
    'incrementId' => $order->getIncrementId(),
    'qliroOrderId' => (int)$link->getQliroOrderId(),
    'grandTotal' => (float)$order->getGrandTotal(),
    'currency' => $order->getOrderCurrencyCode(),
    'itemId' => (int)current($order->getAllVisibleItems())->getId(),
    'qty' => $qty,
    'qliroLines' => $withBundle ? array_map(
        static fn ($line) => [
            'reference' => (string)$line->getMerchantReference(),
            'quantity' => (float)$line->getQuantity(),
            'price' => (float)$line->getPricePerItemIncVat(),
            'vatRate' => (float)$line->getVatRate(),
        ],
        $om->get(\Qliro\QliroOne\Api\Client\MerchantInterface::class)
            ->getOrder((int)$link->getQliroOrderId())->getOrderItems()
    ) : [],
], JSON_PRETTY_PRINT) . PHP_EOL;

/**
 * The bundle, created on the first run with an option per child, and the buy request for it
 */
function qliroE2eBundle($om, $productRepository, int $qty): array
{
    $children = ['QLIRO-E2E-BUNDLE-A' => 4.79, 'QLIRO-E2E-BUNDLE-B' => 34.00];

    try {
        $productRepository->get('QLIRO-E2E-BUNDLE');
    } catch (\Magento\Framework\Exception\NoSuchEntityException $exception) {
        $options = [];

        foreach ($children as $sku => $price) {
            try {
                $productRepository->get($sku);
            } catch (\Magento\Framework\Exception\NoSuchEntityException $exception) {
                qliroE2eSaveChild($om, $productRepository, $sku, $price);
            }

            $link = $om->create(\Magento\Bundle\Api\Data\LinkInterface::class);
            $link->setSku($sku)->setQty(1)->setIsDefault(true)->setCanChangeQuantity(0)->setPrice(0)->setPriceType(0);
            $option = $om->create(\Magento\Bundle\Api\Data\OptionInterface::class);
            $option->setTitle($sku)->setType('select')->setRequired(true)->setPosition(count($options) + 1)
                ->setProductLinks([$link]);
            $options[] = $option;
        }

        $bundle = $om->create(\Magento\Catalog\Model\Product::class);
        $bundle->setSku('QLIRO-E2E-BUNDLE')
            ->setName('Qliro e2e bundle')
            ->setAttributeSetId(4)
            ->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
            ->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_BOTH)
            ->setTypeId(\Magento\Bundle\Model\Product\Type::TYPE_CODE)
            ->setPriceType(\Magento\Bundle\Model\Product\Price::PRICE_TYPE_DYNAMIC)
            ->setSkuType(0)
            ->setWeightType(0)
            ->setPriceView(0)
            ->setShipmentType(0)
            ->setWebsiteIds([1])
            ->setStockData(['use_config_manage_stock' => 1, 'is_in_stock' => 1]);
        $bundle->getExtensionAttributes()->setBundleProductOptions($options);
        $productRepository->save($bundle);
    }

    $bundle = $productRepository->get('QLIRO-E2E-BUNDLE', false, null, true);

    // Indexers run on schedule and there may be no cron, so the cart would not see the stock
    if (!$bundle->isSalable()) {
        $indexers = $om->get(\Magento\Framework\Indexer\ConfigInterface::class)->getIndexers();
        foreach (['cataloginventory_stock', 'inventory', 'catalog_product_price'] as $indexer) {
            if (isset($indexers[$indexer])) {
                $om->create(\Magento\Indexer\Model\Indexer::class)->load($indexer)->reindexAll();
            }
        }

        // A product loaded before the stock was indexed keeps the salability it had then
        $bundle = $productRepository->get('QLIRO-E2E-BUNDLE', false, null, true);
    }

    $type = $bundle->getTypeInstance();
    $selection = [];

    foreach ($type->getSelectionsCollection($type->getOptionsIds($bundle), $bundle) as $item) {
        $selection[(int)$item->getOptionId()] = (int)$item->getSelectionId();
    }

    return [$bundle, new \Magento\Framework\DataObject([
        'qty' => $qty,
        'bundle_option' => $selection,
        'bundle_option_qty' => array_fill_keys(array_keys($selection), 1),
    ])];
}

/**
 * A child of the bundle, Taxable Goods
 */
function qliroE2eSaveChild($om, $productRepository, string $sku, float $price): void
{
    $child = $om->create(\Magento\Catalog\Model\Product::class);
    $child->setSku($sku)
        ->setName('Qliro e2e bundle item ' . substr($sku, -1))
        ->setAttributeSetId(4)
        ->setStatus(\Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_ENABLED)
        ->setVisibility(\Magento\Catalog\Model\Product\Visibility::VISIBILITY_NOT_VISIBLE)
        ->setTypeId(\Magento\Catalog\Model\Product\Type::TYPE_SIMPLE)
        ->setPrice($price)
        ->setTaxClassId(2)
        ->setWebsiteIds([1])
        ->setStockData(['use_config_manage_stock' => 1, 'qty' => 1000, 'is_in_stock' => 1]);
    $productRepository->save($child);
}
