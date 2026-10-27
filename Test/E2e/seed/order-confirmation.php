<?php
/**
 * Helpers for tests/order-confirmation.spec.ts, inside the Magento container:
 *   php var/order-confirmation.php --do=other --email=<address>   places an order paid by check / money order
 *   php var/order-confirmation.php --do=state --order=<id>        what the order shows about its confirmation
 *
 * Prints one JSON object.
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;

require '/var/www/html/app/bootstrap.php';

// getopt stops at the first bare word, so the command is an option too
$options = getopt('', ['do:', 'email::', 'order::']);
$command = (string)($options['do'] ?? '');

$bootstrap = Bootstrap::create(BP, []);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');

if ($command === 'other') {
    $email = (string)$options['email'];
    $storeManager = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
    $store = $storeManager->getStore(1);
    $storeManager->setCurrentStore($store);

    $product = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->get('QLIRO-E2E-PRODUCT');

    $quote = $om->create(\Magento\Quote\Model\Quote::class);
    $quote->setStore($store);
    $quote->setCurrency();
    $quote->addProduct($product, 1);

    $address = [
        'firstname' => 'Other', 'lastname' => 'Method', 'street' => ['1 Test Street'],
        'city' => 'Austin', 'country_id' => 'US', 'region_id' => 57, 'postcode' => '78701',
        'telephone' => '0000000000', 'email' => $email,
    ];
    $quote->getBillingAddress()->addData($address);
    $quote->getShippingAddress()->addData($address);
    $quote->getShippingAddress()->setCollectShippingRates(true)->collectShippingRates()
        ->setShippingMethod('flatrate_flatrate');
    $quote->setCheckoutMethod('guest')->setCustomerIsGuest(true)->setCustomerEmail($email);
    $quote->collectTotals();
    $quoteRepository = $om->get(\Magento\Quote\Api\CartRepositoryInterface::class);
    $quoteRepository->save($quote);

    // the payment needs a saved quote
    $quote = $quoteRepository->get((int)$quote->getId());
    $quote->getPayment()->importData(['method' => 'checkmo']);
    $quote->collectTotals();
    $quoteRepository->save($quote);

    $order = $om->get(\Magento\Quote\Api\CartManagementInterface::class)->submit($quote);

    echo json_encode(['orderId' => (int)$order->getId(), 'incrementId' => $order->getIncrementId()]) . PHP_EOL;
    exit(0);
}

if ($command === 'state') {
    $order = $om->create(\Magento\Sales\Model\Order::class)->load((int)$options['order']);

    echo json_encode([
        'state' => $order->getState(),
        'status' => $order->getStatus(),
        'emailSent' => (bool)$order->getEmailSent(),
    ]) . PHP_EOL;
    exit(0);
}

fwrite(STDERR, "Unknown command {$command}\n");
exit(1);
