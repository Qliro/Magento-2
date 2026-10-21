<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace QliroE2e\EarlyCaptureCallback\Observer;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Management\AbstractManagement;
use Qliro\QliroOne\Model\Security\CallbackToken;

/**
 * Sends Qliro's capture callback while the shipment is still being saved, the way Qliro does half a
 * second after MarkItemsAsShipped. It is a separate request, so it sees only what is committed.
 *
 * Only for an order the test asked for in var/qliro-e2e/early-callback-<increment id>.json, which
 * holds the store URL the container can reach. The answer goes to the same name with .result.json.
 */
class SendCallback implements ObserverInterface
{
    /**
     * @param DirectoryList $directoryList
     * @param CallbackToken $callbackToken
     * @param LinkRepositoryInterface $linkRepository
     */
    public function __construct(
        private readonly DirectoryList $directoryList,
        private readonly CallbackToken $callbackToken,
        private readonly LinkRepositoryInterface $linkRepository
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        /** @var \Magento\Sales\Model\Order\Shipment $shipment */
        $shipment = $observer->getEvent()->getShipment();
        $order = $shipment->getOrder();
        $file = $this->directoryList->getPath(DirectoryList::VAR_DIR)
            . '/qliro-e2e/early-callback-' . $order->getIncrementId();
        $transactionId = $order->getData(AbstractManagement::QLIRO_CAPTURE_TRANSACTION_ID);

        if (!is_file($file . '.json') || !$transactionId) {
            return;
        }

        $request = json_decode((string)file_get_contents($file . '.json'), true);
        $body = [
            'OrderId' => (int)$this->linkRepository->getByOrderId($order->getId(), false)->getQliroOrderId(),
            'MerchantReference' => $order->getIncrementId(),
            'PaymentTransactionId' => (int)$transactionId,
            'Status' => 'Success',
            'PaymentType' => 'Capture',
            'Amount' => (float)$order->getGrandTotal(),
            'Currency' => $order->getOrderCurrencyCode(),
        ];

        $curl = curl_init(sprintf(
            '%s/checkout/qliro_callback/transactionStatus?token=%s',
            rtrim($request['url'], '/'),
            urlencode($this->callbackToken->getToken())
        ));
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);
        $response = curl_exec($curl);

        file_put_contents($file . '.result.json', json_encode([
            'request' => $body,
            'status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE),
            'response' => json_decode((string)$response, true),
        ]));
    }
}
