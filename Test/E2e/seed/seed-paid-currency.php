<?php
/**
 * A buyer who switched the store to another display currency pays Qliro in it, and Qliro's checkout
 * status push places the order. The push has no storefront session, so the two steps run in two
 * processes, as they do on a store:
 *
 *   php var/seed-paid-currency.php --step=quote --currency=EUR   the buyer's cart, in their currency
 *   php var/seed-paid-currency.php --step=shipping --quote=<id>  Qliro asking for delivery methods
 *   php var/seed-paid-currency.php --step=place --quote=<id>     the push
 *
 * The last two run as Qliro's server calls do, without the buyer's session and so without their
 * currency, and both save the quote.
 *
 * A Qliro order built from the quote's own totals, with the payment method of the sandbox fixture,
 * takes the place of the Qliro API. Each step prints
 * one JSON object.
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;

require '/var/www/html/app/bootstrap.php';

$options = getopt('', ['step:', 'currency::', 'quote::']);
$step = (string)($options['step'] ?? '');

$bootstrap = Bootstrap::create(BP, []);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');

$storeManager = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
$store = $storeManager->getStore(1);
$storeManager->setCurrentStore($store);
$quoteRepository = $om->get(\Magento\Quote\Api\CartRepositoryInterface::class);

if ($step === 'quote') {
    $currency = (string)($options['currency'] ?? 'EUR');
    if (!in_array($currency, $store->getAvailableCurrencyCodes(true), true)
        || !$store->getBaseCurrency()->getRate($currency)) {
        fwrite(STDERR, sprintf("the store has no %s with a rate from %s\n", $currency, $store->getBaseCurrencyCode()));
        exit(1);
    }
    // What the storefront currency switcher leaves in the buyer's session
    $store->setCurrentCurrencyCode($currency);

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

    $quote = $om->create(\Magento\Quote\Model\Quote::class);
    $quote->setStore($store);
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
    $quoteRepository->save($quote);
    $quote->getPayment()->importData(['method' => 'qliroone']);
    $quote->collectTotals();
    $quoteRepository->save($quote);

    // a guest cart placed through the checkout API needs its masked id, the real checkout creates it
    $om->get(\Magento\Quote\Model\QuoteIdMaskFactory::class)->create()
        ->setQuoteId((int)$quote->getId())->save();

    $qliroOrderId = 910000 + (int)$quote->getId();
    $link = $om->create(\Qliro\QliroOne\Model\Link::class);
    $link->setQuoteId((int)$quote->getId())
        ->setQliroOrderId($qliroOrderId)
        ->setReference('e2e-' . $quote->getId())
        ->setQliroOrderStatus('InProcess')
        ->setQuoteSnapshot('e2e')
        ->setIsActive(1);
    $om->get(\Qliro\QliroOne\Api\LinkRepositoryInterface::class)->save($link);

    echo json_encode([
        'quoteId' => (int)$quote->getId(),
        'qliroOrderId' => $qliroOrderId,
        'currency' => $quote->getQuoteCurrencyCode(),
        'baseCurrency' => $quote->getBaseCurrencyCode(),
        'rate' => (float)$quote->getBaseToQuoteRate(),
        'grandTotal' => (float)$quote->getGrandTotal(),
    ], JSON_PRETTY_PRINT) . PHP_EOL;

    return;
}

if ($step === 'shipping') {
    $quote = $quoteRepository->get((int)($options['quote'] ?? 0));
    $link = $om->get(\Qliro\QliroOne\Api\LinkRepositoryInterface::class)->getByQuoteId((int)$quote->getId());
    $address = ['Street' => '1 Test Street', 'PostalCode' => '78701', 'City' => 'Austin', 'CountryCode' => 'US'];
    $notification = $om->get(\Qliro\QliroOne\Model\ContainerMapper::class)->fromArray(
        [
            'OrderId' => $link->getQliroOrderId(),
            'MerchantReference' => $link->getReference(),
            'CountryCode' => 'US',
            'ShippingAddress' => $address,
            'Customer' => ['Email' => 'qliro.e2e@example.com', 'Address' => $address],
        ],
        \Qliro\QliroOne\Api\Data\UpdateShippingMethodsNotificationInterface::class
    );
    $response = $om->get(\Qliro\QliroOne\Model\Management\ShippingMethod::class)->get($notification);
    $saved = $om->create(\Magento\Quote\Model\Quote::class)->loadByIdWithoutStore((int)$quote->getId());

    echo json_encode([
        'declineReason' => $response->getDeclineReason(),
        'methods' => count($response->getAvailableShippingMethods() ?? []),
        'currency' => $saved->getQuoteCurrencyCode(),
        'rate' => (float)$saved->getBaseToQuoteRate(),
    ], JSON_PRETTY_PRINT) . PHP_EOL;

    return;
}

if ($step !== 'place') {
    fwrite(STDERR, "usage: seed-paid-currency.php --step=quote --currency=EUR | --step=shipping|place --quote=<id>\n");
    exit(1);
}

$quote = $quoteRepository->get((int)($options['quote'] ?? 0));
$link = $om->get(\Qliro\QliroOne\Api\LinkRepositoryInterface::class)->getByQuoteId((int)$quote->getId());
$shipping = $quote->getShippingAddress();
$modulePath = $om->get(\Magento\Framework\Component\ComponentRegistrar::class)
    ->getPath(\Magento\Framework\Component\ComponentRegistrar::MODULE, 'Qliro_QliroOne');
$fixture = json_decode(
    (string)file_get_contents($modulePath . '/Test/Fixtures/qliro/qliro-get-order-response.completed.v1.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);

// What Qliro charged: the quote's totals in the buyer's currency, read before anything recollects them
$qliroOrder = $om->get(\Qliro\QliroOne\Model\ContainerMapper::class)->fromArray(
    [
        'OrderId' => $link->getQliroOrderId(),
        'MerchantReference' => $link->getReference(),
        'CustomerCheckoutStatus' => 'Completed',
        'TotalPrice' => (float)$quote->getGrandTotal(),
        'Currency' => $quote->getQuoteCurrencyCode(),
        'Country' => 'US',
        'PaymentMethod' => $fixture['PaymentMethod'],
        'OrderItems' => [[
            'MerchantReference' => 'flatrate_flatrate', 'Description' => 'Flat Rate', 'Type' => 'Shipping',
            'Quantity' => 1,
            'PricePerItemIncVat' => (float)$shipping->getShippingInclTax(),
            'PricePerItemExVat' => (float)$shipping->getShippingAmount(),
        ]],
    ],
    \Qliro\QliroOne\Api\Data\QliroOrderInterface::class
);

$placeOrder = $om->create(\Qliro\QliroOne\Model\Management\PlaceOrder::class);
$placeOrder->setQuote($quote);
$order = $placeOrder->execute($qliroOrder, \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);

echo json_encode([
    'orderId' => (int)$order->getId(),
    'incrementId' => $order->getIncrementId(),
    'currency' => $order->getOrderCurrencyCode(),
    'baseCurrency' => $order->getBaseCurrencyCode(),
    'rate' => (float)$order->getBaseToOrderRate(),
    'grandTotal' => (float)$order->getGrandTotal(),
    'baseGrandTotal' => (float)$order->getBaseGrandTotal(),
    'qliroTotal' => (float)$qliroOrder->getTotalPrice(),
], JSON_PRETTY_PRINT) . PHP_EOL;
