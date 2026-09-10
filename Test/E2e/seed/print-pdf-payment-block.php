<?php
/**
 * Prints the payment block of an order the way Magento's PDF renderer receives it, one line per
 * row separator. That block goes on the invoice, the credit memo and the shipment, and there is
 * no way to read it out of the rendered PDF, so the test asserts on this instead.
 *
 * Usage, inside the Magento container:
 *   php var/print-pdf-payment-block.php <orderId>
 */
declare(strict_types=1);

use Magento\Framework\App\Bootstrap;

require '/var/www/html/app/bootstrap.php';

$bootstrap = Bootstrap::create(BP, []);
$om = $bootstrap->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('adminhtml');
$om->get(\Magento\Framework\View\DesignInterface::class)->setDefaultDesignTheme();

$order = $om->get(\Magento\Sales\Api\OrderRepositoryInterface::class)->get((int)$argv[1]);
$block = $om->get(\Magento\Payment\Helper\Data::class)->getInfoBlock($order->getPayment());
$rendered = htmlspecialchars_decode((string)$block->setIsSecureMode(true)->toPdf(), ENT_QUOTES);

foreach (explode('{{pdf_row_separator}}', $rendered) as $line) {
    $line = strip_tags(trim($line));

    if ($line !== '') {
        echo $line . PHP_EOL;
    }
}
