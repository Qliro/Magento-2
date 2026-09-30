<?php
/**
 * Seeds the PLIN-461 shape: a quote rated for a paid and a free delivery and left on the paid one,
 * while the buyer's final choice in Qliro is the free one.
 *
 * Usage, inside the Magento container:
 *   php var/seed-shipping-mismatch.php quote
 *       a quote on flatrate_flatrate with its link, and the validate callback for it
 *   php var/seed-shipping-mismatch.php place --quote=ID [--shipping=freeshipping_freeshipping]
 *       places the order from a Qliro order whose shipping line is that delivery
 *   php var/seed-shipping-mismatch.php cart --masked=MASKED_ID
 *       the id and the checkout token of a guest cart the browser made
 *   php var/seed-shipping-mismatch.php read --quote=ID
 *       the delivery the quote is on
 *   php var/seed-shipping-mismatch.php log --quote=ID
 *       how many times validate put the quote on Qliro's delivery
 *
 * Prints one JSON object.
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;

require '/var/www/html/app/bootstrap.php';

const PAID = 'flatrate_flatrate';
const FREE = 'freeshipping_freeshipping';

$command = $argv[1] ?? '';
$options = [];

// getopt() stops at the command, so the options after it are read here
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

$product = static function () use ($om) {
    $sku = 'QLIRO-E2E-PRODUCT';
    $repository = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);

    try {
        return $repository->get($sku);
    } catch (\Magento\Framework\Exception\NoSuchEntityException $exception) {
        $product = $om->create(\Magento\Catalog\Model\Product::class);
        $product->setSku($sku)
            ->setName('Qliro e2e product')
            ->setUrlKey('qliro-e2e-product')
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

// The price of a delivery the quote was rated for, as Qliro would carry it on its shipping line
$ratePrices = static function ($quote): array {
    $prices = [];
    $address = $quote->getShippingAddress();
    $current = $address->getShippingMethod();

    foreach ($address->getAllShippingRates() as $rate) {
        $address->setShippingMethod($rate->getCode());
        $quote->setTotalsCollectedFlag(false)->collectTotals();
        $prices[$rate->getCode()] = [
            'incVat' => (float)$address->getShippingInclTax(),
            'exVat' => (float)$address->getShippingAmount(),
        ];
    }

    $address->setShippingMethod($current);
    $quote->setTotalsCollectedFlag(false)->collectTotals();

    return $prices;
};

$shippingLine = static function (string $code, array $price): array {
    return [
        'MerchantReference' => $code, 'Description' => $code, 'Type' => 'Shipping', 'Quantity' => 1,
        'PricePerItemIncVat' => $price['incVat'], 'PricePerItemExVat' => $price['exVat'],
    ];
};

switch ($command) {
    case 'quote':
        $quote = $om->create(\Magento\Quote\Model\Quote::class);
        $quote->setStore($store);
        $quote->setCurrency();
        $quote->addProduct($product(), 1);

        $address = [
            'firstname' => 'Qliro', 'lastname' => 'Tester', 'street' => ['Testgatan 1'],
            'city' => 'Stockholm', 'country_id' => 'SE', 'postcode' => '11122',
            'telephone' => '0700000000', 'email' => 'qliro.e2e@example.com',
        ];
        $quote->getBillingAddress()->addData($address);
        $quote->getShippingAddress()->addData($address);
        // Rated inside the totals, where the subtotal free shipping compares against is known
        $quote->getShippingAddress()->setCollectShippingRates(true)->setShippingMethod(PAID);
        $quote->setCheckoutMethod('guest')->setCustomerIsGuest(true)->setCustomerEmail($address['email']);
        $quote->collectTotals();
        $quoteRepository->save($quote);

        // A payment is imported on a saved quote only, as the original seeder does
        $quote = $quoteRepository->get((int)$quote->getId());
        $quote->getPayment()->importData(['method' => 'qliroone']);
        $quote->collectTotals();
        $quoteRepository->save($quote);
        $quote = $quoteRepository->get((int)$quote->getId());
        $prices = $ratePrices($quote);

        // A guest cart is placed through the checkout API by its masked id
        $om->get(\Magento\Quote\Model\QuoteIdMaskFactory::class)->create()
            ->setQuoteId((int)$quote->getId())->save();

        // Far from the 900000 range seed-qliro-order.php uses, the two share the quote id sequence
        $qliroOrderId = 700000000 + (int)$quote->getId();
        $reference = 'e2e-ship-' . $quote->getId();

        $link = $om->create(\Qliro\QliroOne\Model\Link::class);
        $link->setQuoteId((int)$quote->getId())
            ->setQliroOrderId($qliroOrderId)
            ->setReference($reference)
            ->setQliroOrderStatus('InProcess')
            ->setQuoteSnapshot('e2e')
            ->setIsActive(1);
        $linkRepository->save($link);

        // The lines the module itself would send Qliro for this cart, so validate compares like with like
        $items = $om->create(\Qliro\QliroOne\Model\QliroOrder\Builder\OrderItemsBuilder::class)
            ->setQuote($quote)->create();
        $productLines = [];

        foreach ($items as $item) {
            if ($item->getType() === \Qliro\QliroOne\Api\Data\QliroOrderItemInterface::TYPE_PRODUCT) {
                $productLines[] = $containerMapper->toArray($item);
            }
        }

        $qliroAddress = [
            'FirstName' => 'Qliro', 'LastName' => 'Tester', 'Street' => 'Testgatan 1',
            'PostalCode' => '11122', 'City' => 'Stockholm', 'CountryCode' => 'SE',
        ];

        $out([
            'quoteId' => (int)$quote->getId(),
            'qliroOrderId' => $qliroOrderId,
            'callbackToken' => $om->get(\Qliro\QliroOne\Model\Security\CallbackToken::class)->getToken(),
            'prices' => $prices,
            'productLines' => $productLines,
            'validate' => [
                'OrderId' => $qliroOrderId,
                'MerchantReference' => $reference,
                'Currency' => $quote->getQuoteCurrencyCode(),
                'Customer' => [
                    'Email' => $address['email'], 'MobileNumber' => $address['telephone'],
                    'FirstName' => 'Qliro', 'LastName' => 'Tester', 'Address' => $qliroAddress,
                ],
                'ShippingAddress' => $qliroAddress,
                'SelectedShippingMethod' => FREE,
            ],
        ]);
        break;

    case 'place':
        $quote = $quoteRepository->get((int)$options['quote']);
        $code = (string)($options['shipping'] ?? FREE);
        $prices = $ratePrices($quote);
        $link = $linkRepository->getByQuoteId((int)$quote->getId());

        $orderItems = [];
        foreach ($om->create(\Qliro\QliroOne\Model\QliroOrder\Builder\OrderItemsBuilder::class)
            ->setQuote($quote)->create() as $item) {
            if ($item->getType() === \Qliro\QliroOne\Api\Data\QliroOrderItemInterface::TYPE_PRODUCT) {
                $orderItems[] = $containerMapper->toArray($item);
            }
        }
        $orderItems[] = $shippingLine($code, $prices[$code]);

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
                'PaymentMethod' => ['PaymentMethodName' => 'QLIRO_CARD', 'PaymentTypeCode' => 'CARD'],
                'OrderItems' => $orderItems,
            ],
            \Qliro\QliroOne\Api\Data\QliroOrderInterface::class
        );

        $placeOrder = $om->create(\Qliro\QliroOne\Model\Management\PlaceOrder::class);
        $placeOrder->setQuote($quote);
        $order = $placeOrder->execute($qliroOrder, \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);

        $out([
            'orderId' => (int)$order->getId(),
            'incrementId' => $order->getIncrementId(),
            'shippingMethod' => $order->getShippingMethod(),
            'shippingInclTax' => (float)$order->getShippingInclTax(),
            'grandTotal' => (float)$order->getGrandTotal(),
            'qliroTotal' => round($qliroTotal, 2),
        ]);
        break;

    case 'cart':
        $quoteId = (int)$om->create(\Magento\Quote\Model\QuoteIdMask::class)
            ->load((string)$options['masked'], 'masked_id')->getQuoteId();
        $quote = $quoteRepository->get($quoteId);

        $out([
            'quoteId' => $quoteId,
            'ajaxToken' => $om->create(\Qliro\QliroOne\Model\Security\AjaxToken::class)->setQuote($quote)->getToken(),
        ]);
        break;

    case 'read':
        $quote = $om->create(\Magento\Quote\Model\Quote::class)->loadByIdWithoutStore((int)$options['quote']);

        $out(['shippingMethod' => (string)$quote->getShippingAddress()->getShippingMethod()]);
        break;

    case 'log':
        // What validate recorded for the quote
        $connection = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();
        $select = $connection->select()
            ->from($connection->getTableName('qliroone_log'), ['message'])
            ->where('message LIKE ?', 'CALLBACK:VALIDATE: applied the method Qliro selected')
            ->where('extra LIKE ?', '%"quote_id": "' . (int)$options['quote'] . '"%');

        $out(['applied' => count($connection->fetchCol($select))]);
        break;

    default:
        fwrite(STDERR, "Unknown command, see the usage at the top of this file\n");
        exit(1);
}
