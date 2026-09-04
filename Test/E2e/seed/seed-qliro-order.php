<?php
/**
 * Seeds a Magento order the way the module places one: a Qliro order fixture takes the place of
 * the Qliro API, so no merchant credentials are involved and the whole path from the fetched
 * order to the placed Magento order runs for real.
 *
 * The payment method and the shape of the fee lines come from the contract fixtures in
 * Test/Fixtures/qliro, which are the payloads PIS pins from the Qliro sandbox, so the order looks
 * the way a real one does rather than the way we imagine one.
 *
 * Usage, inside the Magento container:
 *   php var/seed-qliro-order.php [--no-name] [--fees=29,10] [--fixture=external-capture]
 *
 * Prints one JSON object describing what it created.
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;

require '/var/www/html/app/bootstrap.php';

$options = getopt('', ['no-name', 'fees::', 'fixture::']);
$withName = !array_key_exists('no-name', $options);
$fees = array_filter(array_map('floatval', explode(',', (string)($options['fees'] ?? '29,10'))));
$fixtureName = (string)($options['fixture'] ?? 'external-capture');

$bootstrap = Bootstrap::create(BP, []);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');

/* ------------------------------------------------------- the contract fixtures */
$modulePath = $om->get(\Magento\Framework\Component\ComponentRegistrar::class)
    ->getPath(\Magento\Framework\Component\ComponentRegistrar::MODULE, 'Qliro_QliroOne');

$readFixture = static function (string $name) use ($modulePath): array {
    $path = sprintf('%s/Test/Fixtures/qliro/qliro-get-order-response.%s.v1.json', $modulePath, $name);

    return json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
};

$paymentMethod = $readFixture($fixtureName)['PaymentMethod'];
$methodCode = (string)($paymentMethod['PaymentTypeCode'] ?? '');
$methodName = (string)($paymentMethod['PaymentMethodName'] ?? '');

if (!$withName) {
    unset($paymentMethod['PaymentMethodName']);
}

// the fee line of the fee fixture, with our own amounts, so the totals are ours but the shape is not
$feeTemplate = null;
foreach ($readFixture('completed-with-fee')['OrderItems'] as $item) {
    if (($item['Type'] ?? '') === 'Fee') {
        $feeTemplate = $item;
        break;
    }
}

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

$quote = $om->create(\Magento\Quote\Model\Quote::class);
$quote->setStore($store);
$quote->setCurrency();
$quote->addProduct($product, 1);

$address = [
    'firstname' => 'Qliro', 'lastname' => 'Tester', 'street' => ['1 Test Street'],
    'city' => 'Austin', 'country_id' => 'US', 'region_id' => 57, 'postcode' => '78701',
    'telephone' => '0000000000', 'email' => 'qliro.e2e@example.com',
];
$quote->getBillingAddress()->addData($address);
$quote->getShippingAddress()->addData($address);
$quote->getShippingAddress()->setCollectShippingRates(true)->collectShippingRates()
    ->setShippingMethod('flatrate_flatrate');
$quote->setCheckoutMethod('guest')->setCustomerIsGuest(true)->setCustomerEmail($address['email']);
$quote->collectTotals();

$quoteRepository = $om->get(\Magento\Quote\Api\CartRepositoryInterface::class);
$quoteRepository->save($quote);

$quote = $quoteRepository->get((int)$quote->getId());
$quote->getPayment()->importData(['method' => 'qliroone']);
$quote->collectTotals();
$quoteRepository->save($quote);

// a guest cart placed through the checkout API needs its masked id, the real checkout creates it
$om->get(\Magento\Quote\Model\QuoteIdMaskFactory::class)->create()
    ->setQuoteId((int)$quote->getId())->save();

/* ---------------------------------------------------------------- the link */
$qliroOrderId = 900000 + (int)$quote->getId();
$reference = 'e2e-' . $quote->getId();

$link = $om->create(\Qliro\QliroOne\Model\Link::class);
$link->setQuoteId((int)$quote->getId())
    ->setQliroOrderId($qliroOrderId)
    ->setReference($reference)
    ->setQliroOrderStatus('InProcess')
    ->setQuoteSnapshot('e2e')
    ->setIsActive(1);
$om->get(\Qliro\QliroOne\Api\LinkRepositoryInterface::class)->save($link);

/* ------------------------------------------------------- the Qliro order */
$shipping = $quote->getShippingAddress();

$orderItems = [[
    'MerchantReference' => 'flatrate_flatrate', 'Description' => 'Flat Rate', 'Type' => 'Shipping',
    'Quantity' => 1,
    'PricePerItemIncVat' => (float)$shipping->getShippingInclTax(),
    'PricePerItemExVat' => (float)$shipping->getShippingAmount(),
]];

foreach ($fees as $index => $amount) {
    $fee = $feeTemplate;
    $fee['MerchantReference'] .= $index === 0 ? '' : '-' . ($index + 1);
    $fee['Description'] .= $index === 0 ? '' : ' ' . ($index + 1);
    $fee['PricePerItemIncVat'] = $amount;
    $fee['PricePerItemExVat'] = $amount;
    $orderItems[] = $fee;
}

$qliroOrder = $om->get(\Qliro\QliroOne\Model\ContainerMapper::class)->fromArray(
    [
        'OrderId' => $qliroOrderId,
        'MerchantReference' => $reference,
        'CustomerCheckoutStatus' => 'Completed',
        'TotalPrice' => (float)$quote->getGrandTotal() + array_sum($fees),
        'Currency' => $quote->getQuoteCurrencyCode(),
        'Country' => 'US',
        'PaymentMethod' => $paymentMethod,
        'OrderItems' => $orderItems,
    ],
    \Qliro\QliroOne\Api\Data\QliroOrderInterface::class
);

/* -------------------------------------------------------------- the order */
$expectedGrandTotal = round((float)$quote->getGrandTotal() + array_sum($fees), 2);

$placeOrder = $om->create(\Qliro\QliroOne\Model\Management\PlaceOrder::class);
$placeOrder->setQuote($quote);
$order = $placeOrder->execute($qliroOrder, \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);

echo json_encode([
    'orderId' => (int)$order->getId(),
    'incrementId' => $order->getIncrementId(),
    'quoteId' => (int)$quote->getId(),
    'qliroOrderId' => $qliroOrderId,
    'reference' => $reference,
    'grandTotal' => (float)$order->getGrandTotal(),
    // what the order has to come to: the quote plus every fee line of the Qliro order
    'expectedGrandTotal' => $expectedGrandTotal,
    'fees' => $fees,
    'fixture' => $fixtureName,
    'methodCode' => $methodCode,
    'methodName' => $withName ? $methodName : null,
], JSON_PRETTY_PRINT) . PHP_EOL;
