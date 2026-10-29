<?php
/**
 * What tests/capture-hold.spec.ts needs inside the Magento container.
 *
 * Usage:
 *   php var/capture-hold.php invoice --order=<entity id> --transaction=<id>
 *                                       invoices one unit offline and records it as that Qliro capture
 *   php var/capture-hold.php ship --order=<entity id> --transaction=<id>
 *                                       ships one unit and records it as that Qliro capture, not invoiced
 *   php var/capture-hold.php erp --order=<entity id> --status=<code>   sets the order's status as an ERP does
 *   php var/capture-hold.php hold --order=<entity id>                  holds the order as a merchant does
 *   php var/capture-hold.php unhold --order=<entity id>                releases it as a merchant does
 *   php var/capture-hold.php assign --status=<code> --state=<state>    creates the status, assigns it
 *   php var/capture-hold.php unassign --status=<code> --state=<state>
 *   php var/capture-hold.php push --order=<entity id> --transaction=<id> --status=Error --url=<store url>
 *                                       Qliro's transaction status callback for that capture
 *   php var/capture-hold.php state --order=<entity id>                 state, status and the history
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
    if (preg_match('/^--([a-z]+)=(.*)$/', $argument, $match)) {
        $options[$match[1]] = $match[2];
    }
}

$bootstrap = Bootstrap::create(BP, []);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

$orderRepository = $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class);
$load = static fn (): Order => $orderRepository->get((int)($options['order'] ?? 0));

switch ($command) {
    case 'invoice':
        $order = $load();
        $item = null;
        foreach ($order->getAllVisibleItems() as $candidate) {
            if ($candidate->getQtyOrdered() > $candidate->getQtyInvoiced()) {
                $item = $candidate;
                break;
            }
        }
        $invoice = $om->get(\Magento\Sales\Model\Service\InvoiceService::class)
            ->prepareInvoice($order, [$item->getId() => 1]);
        $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
        $invoice->register();
        // As the admin invoice does, so the save moves a new order to processing
        $order->setIsInProcess(true);
        $om->create(\Magento\Framework\DB\Transaction::class)
            ->addObject($invoice)->addObject($order)->save();

        // What Management\Payment::captureByInvoice records once Qliro accepted the capture
        $link = $om->get(\Qliro\QliroOne\Api\LinkRepositoryInterface::class)->getByOrderId((int)$order->getId(), false);
        $omStatus = $om->create(\Qliro\QliroOne\Api\Data\OrderManagementStatusInterface::class);
        $omStatus->setRecordId($order->getPayment()->getId())
            ->setRecordType(\Qliro\QliroOne\Api\Data\OrderManagementStatusInterface::RECORD_TYPE_PAYMENT)
            ->setTransactionId($options['transaction'])
            ->setTransactionStatus(\Qliro\QliroOne\Api\Data\QliroOrderManagementStatusInterface::STATUS_CREATED)
            ->setNotificationStatus(\Qliro\QliroOne\Api\Data\OrderManagementStatusInterface::NOTIFICATION_STATUS_DONE)
            ->setMessage('Capture Requested for Invoice')
            ->setQliroOrderId($link->getQliroOrderId());
        $om->get(\Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface::class)->save($omStatus);
        $result = ['invoiceId' => (int)$invoice->getId()];
        break;

    case 'ship':
        $order = $load();
        $item = null;
        foreach ($order->getAllVisibleItems() as $candidate) {
            if ($candidate->getQtyOrdered() > $candidate->getQtyShipped()) {
                $item = $candidate;
                break;
            }
        }
        $shipment = $om->get(\Magento\Sales\Model\Order\ShipmentFactory::class)->create($order, [$item->getId() => 1]);
        $shipment->register();
        $order->setIsInProcess(true);
        $om->create(\Magento\Framework\DB\Transaction::class)
            ->addObject($shipment)->addObject($order)->save();

        // What Management\Payment::captureByShipment records once Qliro accepted the capture
        $link = $om->get(\Qliro\QliroOne\Api\LinkRepositoryInterface::class)->getByOrderId((int)$order->getId(), false);
        $omStatus = $om->create(\Qliro\QliroOne\Api\Data\OrderManagementStatusInterface::class);
        $omStatus->setRecordId($shipment->getId())
            ->setRecordType(\Qliro\QliroOne\Api\Data\OrderManagementStatusInterface::RECORD_TYPE_SHIPMENT)
            ->setTransactionId($options['transaction'])
            ->setTransactionStatus(\Qliro\QliroOne\Api\Data\QliroOrderManagementStatusInterface::STATUS_CREATED)
            ->setNotificationStatus(\Qliro\QliroOne\Api\Data\OrderManagementStatusInterface::NOTIFICATION_STATUS_DONE)
            ->setMessage('Capture Requested for Shipment')
            ->setQliroOrderId($link->getQliroOrderId());
        $om->get(\Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface::class)->save($omStatus);
        $result = ['shipmentId' => (int)$shipment->getId()];
        break;

    case 'erp':
        $order = $load();
        $order->addCommentToStatusHistory('ERP: ' . $options['status'], $options['status']);
        $orderRepository->save($order);
        $result = ['status' => $order->getStatus()];
        break;

    case 'hold':
    case 'unhold':
        // The service the admin buttons, the mass actions and the REST API use
        $management = $om->get(\Magento\Sales\Api\OrderManagementInterface::class);
        $command === 'hold' ? $management->hold((int)$options['order']) : $management->unHold((int)$options['order']);
        $result = ['state' => $load()->getState()];
        break;

    case 'assign':
        $status = $om->create(\Magento\Sales\Model\Order\Status::class)->load($options['status']);
        if (!$status->getStatus()) {
            $status->setStatus($options['status'])->setLabel($options['status'])->save();
        }
        $status->assignState($options['state'], false, true);
        $result = ['assigned' => $options['status']];
        break;

    case 'unassign':
        $om->create(\Magento\Sales\Model\Order\Status::class)->load($options['status'])->unassignState($options['state']);
        $result = ['unassigned' => $options['status']];
        break;

    case 'push':
        $order = $load();
        $link = $om->get(\Qliro\QliroOne\Api\LinkRepositoryInterface::class)->getByOrderId((int)$order->getId(), false);
        $token = $om->get(\Qliro\QliroOne\Model\Security\CallbackToken::class)->getToken();
        $curl = curl_init(sprintf(
            '%s/checkout/qliro_callback/transactionStatus?token=%s',
            rtrim((string)$options['url'], '/'),
            urlencode($token)
        ));
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'OrderId' => (int)$link->getQliroOrderId(),
                'MerchantReference' => $link->getReference(),
                'PaymentTransactionId' => (int)$options['transaction'],
                'Status' => $options['status'],
                'PaymentType' => 'Capture',
                'Amount' => 34.0,
                'Currency' => $order->getOrderCurrencyCode(),
            ]),
        ]);
        $response = curl_exec($curl);
        $result = [
            'status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            'response' => json_decode((string)$response, true),
        ];
        break;

    case 'state':
        $order = $om->create(Order::class)->load((int)($options['order'] ?? 0));
        $result = [
            'state' => $order->getState(),
            'status' => $order->getStatus(),
            'holdBeforeStatus' => $order->getHoldBeforeStatus(),
            'invoiced' => (float)$order->getTotalInvoiced(),
            'history' => array_values(array_map(
                static fn ($entry): string => (string)$entry->getComment(),
                $order->getStatusHistoryCollection()->setOrder('entity_id', 'DESC')->getItems()
            )),
        ];
        break;

    default:
        fwrite(STDERR, "Unknown command '$command'\n");
        exit(1);
}

echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
