<?php
/**
 * Places a guest order of one configurable product with check money order, no Qliro involved,
 * for specs that look up the lines of an order.
 *
 * Usage, inside the Magento container:
 *   php var/seed-configurable-order.php [--sku=MH01]
 *
 * Prints one JSON object: the order id and, for the parent and the child item, the quote item id,
 * the sku and the product id.
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;

require '/var/www/html/app/bootstrap.php';

$om = Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');

$options = getopt('', ['sku::']);
$sku = $options['sku'] ?? 'MH01';

$storeManager = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
$store = $storeManager->getStore(1);
$storeManager->setCurrentStore($store);

$productRepository = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
$product = $productRepository->get($sku, false, 1, true);

// the first child that is in stock, with the values of the attributes that pick it
$typeInstance = $product->getTypeInstance();
$superAttributes = [];
foreach ($typeInstance->getUsedProducts($product) as $child) {
    if (!$child->isSalable()) {
        continue;
    }
    foreach ($typeInstance->getConfigurableAttributesAsArray($product) as $attribute) {
        $superAttributes[$attribute['attribute_id']] = $child->getData($attribute['attribute_code']);
    }
    break;
}

$quote = $om->create(\Magento\Quote\Model\Quote::class);
$quote->setStore($store);
$quote->setCurrency();
$added = $quote->addProduct($product, new \Magento\Framework\DataObject(['qty' => 1, 'super_attribute' => $superAttributes]));
if (is_string($added)) {
    fwrite(STDERR, "Cannot add $sku: $added\n");
    exit(1);
}

$address = [
    'firstname' => 'Qliro', 'lastname' => 'Tester', 'street' => ['Testgatan 1'],
    'city' => 'Stockholm', 'country_id' => 'SE', 'postcode' => '11122',
    'telephone' => '0700000000', 'email' => 'qliro.e2e@example.com',
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
$quote->getPayment()->importData(['method' => 'checkmo']);
$quote->collectTotals();
$quoteRepository->save($quote);

$order = $om->get(\Magento\Quote\Model\QuoteManagement::class)->submit($quote);

$items = [];
foreach ($order->getAllItems() as $item) {
    $items[$item->getParentItem() ? 'child' : 'parent'] = [
        'quoteItemId' => (int)$item->getQuoteItemId(),
        'sku' => $item->getSku(),
        'productId' => (int)$item->getProductId(),
    ];
}

echo json_encode(['orderId' => (int)$order->getId()] + $items);
