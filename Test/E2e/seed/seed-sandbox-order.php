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
 *   php var/seed-sandbox-order.php [--qty=2]
 *
 * Prints one JSON object describing what it created.
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;

require '/var/www/html/app/bootstrap.php';

$options = getopt('', ['qty::']);
$qty = max(1, (int)($options['qty'] ?? 2));

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
$quote->addProduct($product, $qty);
$quote->getBillingAddress()->addData($address);
$quote->getShippingAddress()->addData($address);
$quote->getShippingAddress()->setCollectShippingRates(true)->collectShippingRates()
    ->setShippingMethod('flatrate_flatrate');
$quote->setCheckoutMethod('guest')->setCustomerIsGuest(true)->setCustomerEmail($email);
$quote->collectTotals();

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
], JSON_PRETTY_PRINT) . PHP_EOL;
