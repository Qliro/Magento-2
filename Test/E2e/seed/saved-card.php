<?php
/**
 * Saved card helpers for tests/saved-card-removal.spec.ts, against the Qliro sandbox.
 *
 * Usage, inside the Magento container:
 *   php var/saved-card.php register                 Qliro card registration: {qliroOrderId, snippet}
 *   php var/saved-card.php card --order=ID          the card the registration saved: {savedCardId}
 *   php var/saved-card.php subscription --card=ID [--email=E]
 *                                                   a customer (new, or the one with that email) with an
 *                                                   order and an active subscription on that card:
 *                                                   {orderId, incrementId, email, password}
 *   php var/saved-card.php remove --card=ID         asks Qliro to delete the card: {removed}, false when
 *                                                   Qliro no longer had it
 *
 * Prints one JSON object.
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;

require '/var/www/html/app/bootstrap.php';

$action = $argv[1] ?? '';
// getopt() stops at the action, so the options after it are read here
$options = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--(\w+)=(.*)$/', $arg, $match)) {
        $options[$match[1]] = $match[2];
    }
}

$bootstrap = Bootstrap::create(BP, []);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');
$om->get(\Magento\Store\Model\StoreManagerInterface::class)->setCurrentStore(1);

$service = $om->get(\Qliro\QliroOne\Model\Api\Service::class);

switch ($action) {
    case 'register':
        $response = $service->post('checkout/merchantapi/CreateMerchantSavedCreditCard', [
            'MerchantReference' => 'e2e-card-' . substr(bin2hex(random_bytes(4)), 0, 8),
            'Currency' => 'SEK',
            'Country' => 'SE',
            'Language' => 'en-us',
            'MerchantSavedCreditCardPushUrl' => 'https://example.com/qliro-e2e-saved-card',
            'Customer' => [
                'Email' => 'qliro.e2e.card@example.com',
                'JuridicalType' => 'Physical',
                'MobileNumber' => '0701234567',
                'PersonalNumber' => '7906255307',
            ],
        ], 1);
        echo json_encode([
            'qliroOrderId' => $response['OrderId'],
            'snippet' => $response['OrderForRegistrationTokenHtmlSnippet'],
        ]);
        break;

    case 'card':
        $response = $service->get('checkout/merchantapi/orders/{OrderId}', ['OrderId' => (int)$options['order']], 1);
        echo json_encode(['savedCardId' => $response['MerchantSavedCreditCard']['Id'] ?? null]);
        break;

    case 'subscription':
        echo json_encode(createSubscription($om, (string)$options['card'], $options['email'] ?? null));
        break;

    case 'remove':
        $removed = $om->get(\Qliro\QliroOne\Api\Client\MerchantInterface::class)
            ->removeSavedCreditCard((string)$options['card'], 1);
        echo json_encode(['removed' => $removed]);
        break;

    default:
        fwrite(STDERR, "Unknown action '$action'\n");
        exit(1);
}

/**
 * A new customer, an order of theirs paid by check/money order, and an active monthly subscription
 * on that order carrying the card
 */
function createSubscription($om, string $savedCardId, ?string $email): array
{
    $password = 'Qliro-e2e-123';
    if ($email) {
        $customer = $om->get(\Magento\Customer\Api\CustomerRepositoryInterface::class)->get($email, 1);
    } else {
        $email = 'qliro.e2e.card.' . bin2hex(random_bytes(4)) . '@example.com';
        $customer = $om->create(\Magento\Customer\Api\Data\CustomerInterface::class)
            ->setWebsiteId(1)
            ->setStoreId(1)
            ->setGroupId(1)
            ->setEmail($email)
            ->setFirstname('Qliro')
            ->setLastname('Tester');
        $customer = $om->get(\Magento\Customer\Api\AccountManagementInterface::class)
            ->createAccount($customer, $password);
    }

    $product = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->get('test-simple');
    $quote = $om->create(\Magento\Quote\Model\Quote::class);
    $quote->setStoreId(1)->assignCustomer($customer)->setCurrency();
    $quote->addProduct($product, 1);
    $address = [
        'firstname' => 'Qliro', 'lastname' => 'Tester', 'street' => 'Testgatan 1', 'city' => 'Stockholm',
        'postcode' => '11122', 'country_id' => 'SE', 'telephone' => '0701234567',
        'region_id' => regionId($om),
    ];
    $quote->getBillingAddress()->addData($address);
    $quote->getShippingAddress()->addData($address)
        ->setCollectShippingRates(true)->collectShippingRates()->setShippingMethod('flatrate_flatrate');
    $quote->setPaymentMethod('checkmo');
    $quote->getPayment()->setMethod('checkmo');
    $quote->collectTotals()->save();
    $order = $om->get(\Magento\Quote\Model\QuoteManagement::class)->submit($quote);

    $info = $om->create(\Qliro\QliroOne\Model\RecurringInfo::class);
    $info->setData([
        'original_order_id' => (int)$order->getId(),
        'frequency_option' => '1|month',
        'payment_method' => 'qliroone',
        'saved_credit_card_id' => $savedCardId,
        'next_order_date' => date('Y-m-d', strtotime('+1 month')),
    ]);
    $om->get(\Qliro\QliroOne\Api\RecurringInfoRepositoryInterface::class)->save($info);

    return [
        'orderId' => (int)$order->getId(),
        'incrementId' => $order->getIncrementId(),
        'email' => $email,
        'password' => $password,
    ];
}

/**
 * A Swedish region, for stores that require one for SE
 */
function regionId($om): ?int
{
    $region = $om->create(\Magento\Directory\Model\Region::class)->loadByName('Stockholms län', 'SE');

    return $region->getId() ? (int)$region->getId() : null;
}
