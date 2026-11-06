<?php
/**
 * Seeds a Qliro checkout whose order is not placed yet, for the shipping provider notification.
 *
 * Usage, inside the Magento container:
 *   php var/shipping-notification.php quote
 *       a guest quote on flatrate with its link, and the callback token
 *   php var/shipping-notification.php place --quote=ID
 *       places the order from a completed Qliro order for that quote
 *   php var/shipping-notification.php read --quote=ID
 *       the order of the quote, its shipping description and info, and the update still stored
 *   php var/shipping-notification.php cancel --quote=ID
 *       cancels the order of the quote
 *   php var/shipping-notification.php deactivate --quote=ID
 *       the link inactive, as a refused or replaced checkout leaves it
 *   php var/shipping-notification.php lock --quote=ID | unlock --quote=ID
 *       holds or frees the placement lock of the Qliro order, as a placement in progress does
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
$connection = $om->get(\Magento\Framework\App\ResourceConnection::class)->getConnection();

$out = static function (array $data): void {
    echo json_encode($data, JSON_PRETTY_PRINT) . PHP_EOL;
};

$link = static function () use ($linkRepository, $options) {
    return $linkRepository->getByQuoteId((int)$options['quote'], false);
};

switch ($command) {
    case 'quote':
        $quote = $om->create(\Magento\Quote\Model\Quote::class);
        $quote->setStore($store);
        $quote->setCurrency();
        $quote->addProduct($om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->get('QLIRO-E2E-PRODUCT'), 1);

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

        // A guest cart is placed through the checkout API by its masked id
        $om->get(\Magento\Quote\Model\QuoteIdMaskFactory::class)->create()
            ->setQuoteId((int)$quote->getId())->save();

        // Apart from the ranges the other seeders take from the same quote id sequence
        $qliroOrderId = 600000000 + (int)$quote->getId();
        $reference = 'e2e-note-' . $quote->getId();

        $newLink = $om->create(\Qliro\QliroOne\Model\Link::class);
        $newLink->setQuoteId((int)$quote->getId())
            ->setQliroOrderId($qliroOrderId)
            ->setReference($reference)
            ->setQliroOrderStatus('InProcess')
            ->setQuoteSnapshot('e2e')
            ->setIsActive(1);
        $linkRepository->save($newLink);

        $out([
            'quoteId' => (int)$quote->getId(),
            'qliroOrderId' => $qliroOrderId,
            'reference' => $reference,
            'callbackToken' => $om->get(\Qliro\QliroOne\Model\Security\CallbackToken::class)->getToken(),
        ]);
        break;

    case 'place':
        $quote = $quoteRepository->get((int)$options['quote']);
        $quoteLink = $link();
        $address = $quote->getShippingAddress();

        $orderItems = [];
        foreach ($om->create(\Qliro\QliroOne\Model\QliroOrder\Builder\OrderItemsBuilder::class)
            ->setQuote($quote)->create() as $item) {
            $orderItems[] = $containerMapper->toArray($item);
        }

        $qliroTotal = 0.0;
        foreach ($orderItems as $item) {
            $qliroTotal += $item['PricePerItemIncVat'] * $item['Quantity'];
        }

        $qliroOrder = $containerMapper->fromArray(
            [
                'OrderId' => $quoteLink->getQliroOrderId(),
                'MerchantReference' => $quoteLink->getReference(),
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

        $out(['orderId' => (int)$order->getId(), 'shippingMethod' => (string)$address->getShippingMethod()]);
        break;

    case 'read':
        $quoteLink = $link();
        $stored = $connection->fetchOne(
            $connection->select()
                ->from($connection->getTableName('qliroone_pending_shipping_update'), ['COUNT(*)'])
                ->where('link_id = ?', (int)$quoteLink->getId())
        );
        $result = ['orderId' => (int)$quoteLink->getOrderId(), 'stored' => (int)$stored];

        if ($quoteLink->getOrderId()) {
            $order = $om->create(\Magento\Sales\Api\OrderRepositoryInterface::class)->get($quoteLink->getOrderId());
            $result['shippingDescription'] = $order->getShippingDescription();
            $result['shippingInfo'] = $order->getPayment()->getAdditionalInformation('qliroone_shipping_info');
        }

        $out($result);
        break;

    case 'cancel':
        $order = $om->create(\Magento\Sales\Api\OrderRepositoryInterface::class)->get($link()->getOrderId());
        $order->getPayment()->setNotificationResult(true);
        $order->cancel();
        $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->save($order);
        $out(['state' => $order->getState()]);
        break;

    case 'deactivate':
        $quoteLink = $link();
        $linkRepository->save($quoteLink->setIsActive(0));
        $out(['active' => false]);
        break;

    case 'lock':
        // A live process holds it, so the lock is not retired as abandoned
        $connection->insert($connection->getTableName('qliroone_order_lock'), [
            'qliro_order_id' => $link()->getQliroOrderId(),
            'process_id' => 1,
            'created_at' => new \Zend_Db_Expr('NOW()'),
        ]);
        $out(['locked' => true]);
        break;

    case 'unlock':
        $connection->delete(
            $connection->getTableName('qliroone_order_lock'),
            ['qliro_order_id = ?' => $link()->getQliroOrderId()]
        );
        $out(['locked' => false]);
        break;

    default:
        fwrite(STDERR, "Unknown command, see the usage at the top of this file\n");
        exit(1);
}
