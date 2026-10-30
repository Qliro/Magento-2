<?php
/**
 * What tests/status-for-state.spec.ts needs inside the Magento container.
 *
 * Usage:
 *   php var/status-for-state.php place --status=InProcess   places an order the way the module does
 *   php var/status-for-state.php invoice --order=<id>       invoices it offline, as a merchant would
 *   php var/status-for-state.php capture --order=<id>       records a capture of the invoice as Qliro's
 *   php var/status-for-state.php state --order=<id>         state, status and whether the pair is assigned
 *   php var/status-for-state.php new-status --status=<code> sets New Order Status, prints the one it had;
 *                                                           an empty one removes it
 *
 * Prints one JSON object.
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;
use Magento\Sales\Model\Order;

require '/var/www/html/app/bootstrap.php';

$command = $argv[1] ?? '';
$options = [];

// getopt() stops at the command, so the options after it are read here
foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([a-z-]+)=(.*)$/', $argument, $match)) {
        $options[$match[1]] = $match[2];
    }
}

$bootstrap = Bootstrap::create(BP, []);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');

$resource = $om->get(\Magento\Framework\App\ResourceConnection::class);
$connection = $resource->getConnection();
$loadOrder = static fn (): Order => $om->create(Order::class)->load((int)($options['order'] ?? 0));

switch ($command) {
    case 'place':
        $storeManager = $om->get(\Magento\Store\Model\StoreManagerInterface::class);
        $store = $storeManager->getStore(1);
        $storeManager->setCurrentStore($store);

        $product = $om->get(\Magento\Catalog\Api\ProductRepositoryInterface::class)->get('QLIRO-E2E-PRODUCT');
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

        // the payment needs a saved quote
        $quote = $quoteRepository->get((int)$quote->getId());
        $quote->getPayment()->importData(['method' => 'qliroone']);
        $quote->collectTotals();
        $quoteRepository->save($quote);

        // a guest cart placed through the checkout API needs its masked id, the real checkout creates it
        $om->get(\Magento\Quote\Model\QuoteIdMaskFactory::class)->create()
            ->setQuoteId((int)$quote->getId())->save();

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

        $modulePath = $om->get(\Magento\Framework\Component\ComponentRegistrar::class)
            ->getPath(\Magento\Framework\Component\ComponentRegistrar::MODULE, 'Qliro_QliroOne');
        $fixture = json_decode(
            (string)file_get_contents($modulePath . '/Test/Fixtures/qliro/qliro-get-order-response.external-capture.v1.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $shipping = $quote->getShippingAddress();

        $qliroOrder = $om->get(\Qliro\QliroOne\Model\ContainerMapper::class)->fromArray(
            [
                'OrderId' => $qliroOrderId,
                'MerchantReference' => $reference,
                'CustomerCheckoutStatus' => (string)($options['status'] ?? 'Completed'),
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
        $order = $placeOrder->execute($qliroOrder, Order::STATE_PENDING_PAYMENT);

        $result = [
            'orderId' => (int)$order->getId(),
            'qliroOrderId' => $qliroOrderId,
            'reference' => $reference,
            'callbackToken' => $om->get(\Qliro\QliroOne\Model\Security\CallbackToken::class)->getToken(),
        ];
        break;

    case 'invoice':
        $order = $loadOrder();
        $invoice = $order->prepareInvoice();
        $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE)->register();
        $order->setIsInProcess(true);
        $om->create(\Magento\Framework\DB\Transaction::class)->addObject($invoice)->addObject($order)->save();
        $result = ['invoiceId' => (int)$invoice->getId()];
        break;

    case 'capture':
        $order = $loadOrder();
        $transactionId = (string)(800000000 + (int)$order->getId());
        $omStatus = $om->create(\Qliro\QliroOne\Model\OrderManagementStatus::class);
        $omStatus->setRecordType(\Qliro\QliroOne\Api\Data\OrderManagementStatusInterface::RECORD_TYPE_PAYMENT)
            ->setRecordId((int)$order->getPayment()->getId())
            ->setTransactionId($transactionId)
            ->setTransactionStatus('Created')
            ->setQliroOrderId((int)$om->get(\Qliro\QliroOne\Api\LinkRepositoryInterface::class)
                ->getByOrderId((int)$order->getId(), false)->getQliroOrderId())
            ->setNotificationStatus(\Qliro\QliroOne\Api\Data\OrderManagementStatusInterface::NOTIFICATION_STATUS_DONE)
            ->setMessage('e2e capture');
        $om->get(\Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface::class)->save($omStatus);
        $result = ['transactionId' => $transactionId];
        break;

    case 'state':
        $order = $loadOrder();
        $assigned = static fn ($state, $status): bool => (bool)$connection->fetchOne(
            $connection->select()
                ->from($resource->getTableName('sales_order_status_state'), ['status'])
                ->where('state = ?', (string)$state)
                ->where('status = ?', (string)$status)
        );
        $result = [
            'state' => $order->getState(),
            'status' => $order->getStatus(),
            'assigned' => $assigned($order->getState(), $order->getStatus()),
            'gridStatus' => $connection->fetchOne(
                $connection->select()
                    ->from($resource->getTableName('sales_order_grid'), ['status'])
                    ->where('entity_id = ?', (int)$order->getId())
            ),
        ];
        break;

    case 'new-status':
        $path = 'payment/qliroone/api/order_status';
        $writer = $om->get(\Magento\Framework\App\Config\Storage\WriterInterface::class);
        $status = (string)($options['status'] ?? '');
        $before = $connection->fetchOne(
            $connection->select()
                ->from($resource->getTableName('core_config_data'), ['value'])
                ->where('scope = ?', 'default')
                ->where('path = ?', $path)
        );
        $status === '' ? $writer->delete($path) : $writer->save($path, $status);
        $om->get(\Magento\Framework\App\Cache\TypeListInterface::class)->cleanType('config');
        $result = ['before' => $before === false ? '' : (string)$before];
        break;

    default:
        fwrite(STDERR, "Unknown command '$command'\n");
        exit(1);
}

echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
