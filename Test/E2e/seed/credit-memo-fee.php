<?php
/**
 * What tests/credit-memo-fee.spec.ts needs inside the Magento container.
 *
 * Usage:
 *   php var/credit-memo-fee.php invoice --order=<entity id>      invoices the whole order offline
 *   php var/credit-memo-fee.php memo --order=<entity id> --invoice=<entity id> [--qty=1] [--shipping=0]
 *       [--adjustmentNegative=0.50] [--adjustmentPositive=0.50]
 *                                                               refunds that much of the invoice offline
 *
 * Prints one JSON object.
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;

require '/var/www/html/app/bootstrap.php';

$command = $argv[1] ?? '';
$options = [];

foreach (array_slice($argv, 2) as $argument) {
    if (preg_match('/^--([a-zA-Z]+)=(.*)$/', $argument, $match)) {
        $options[$match[1]] = $match[2];
    }
}

$bootstrap = Bootstrap::create(BP, []);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');

$orderRepository = $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class);
$order = $orderRepository->get((int)($options['order'] ?? 0));

switch ($command) {
    case 'invoice':
        $invoice = $om->get(\Magento\Sales\Model\Service\InvoiceService::class)->prepareInvoice($order);
        $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
        $invoice->register();
        $order->setIsInProcess(true);
        $om->create(\Magento\Framework\DB\Transaction::class)
            ->addObject($invoice)->addObject($order)->save();
        $result = ['invoiceId' => (int)$invoice->getId(), 'grandTotal' => (float)$invoice->getGrandTotal()];
        break;

    case 'memo':
        $invoice = $om->get(\Magento\Sales\Api\InvoiceRepositoryInterface::class)->get((int)$options['invoice']);
        $invoice->setOrder($order);
        $qtys = [];
        foreach ($order->getAllItems() as $item) {
            $qtys[$item->getId()] = (float)($options['qty'] ?? 0);
        }
        $data = [
            'qtys' => $qtys,
            'adjustment_positive' => (float)($options['adjustmentPositive'] ?? 0),
            'adjustment_negative' => (float)($options['adjustmentNegative'] ?? 0),
        ];
        // left out, the memo takes the shipping still to refund
        if (isset($options['shipping'])) {
            $data['shipping_amount'] = (float)$options['shipping'];
        }
        $memo = $om->get(\Magento\Sales\Model\Order\CreditmemoFactory::class)->createByInvoice($invoice, $data);
        $om->get(\Magento\Sales\Api\CreditmemoManagementInterface::class)->refund($memo, true);
        $order = $orderRepository->get((int)$order->getId());
        $result = [
            'creditMemoId' => (int)$memo->getId(),
            'grandTotal' => (float)$memo->getGrandTotal(),
            'orderTotalRefunded' => (float)$order->getTotalRefunded(),
            'orderBaseTotalRefunded' => (float)$order->getBaseTotalRefunded(),
        ];
        break;

    default:
        fwrite(STDERR, "unknown command\n");
        exit(1);
}

echo json_encode($result, JSON_PRETTY_PRINT) . PHP_EOL;
