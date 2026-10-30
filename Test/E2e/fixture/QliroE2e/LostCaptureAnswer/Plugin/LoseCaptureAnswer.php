<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace QliroE2e\LostCaptureAnswer\Plugin;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use Magento\Framework\App\Filesystem\DirectoryList;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\AdminMarkItemsAsShippedRequestInterface;
use Qliro\QliroOne\Model\Api\Client\Exception\ClientException;
use Qliro\QliroOne\Model\Exception\TerminalException;

/**
 * Loses Qliro's answer to one capture, the way a timeout does: the capture goes to Qliro for real,
 * then the call fails as if nothing came back.
 *
 * Only for a Qliro order the test asked for in var/qliro-e2e/lose-capture-<qliro order id>, which
 * is removed on use. The lost answer goes to the same name with .result.json.
 */
class LoseCaptureAnswer
{
    /**
     * @param DirectoryList $directoryList
     */
    public function __construct(private readonly DirectoryList $directoryList)
    {
    }

    /**
     * @param OrderManagementInterface $subject
     * @param callable $proceed
     * @param AdminMarkItemsAsShippedRequestInterface $request
     * @param int|null $storeId
     * @return mixed
     * @throws ClientException
     */
    public function aroundMarkItemsAsShipped(
        OrderManagementInterface $subject,
        callable $proceed,
        AdminMarkItemsAsShippedRequestInterface $request,
        $storeId = null
    ) {
        $file = $this->directoryList->getPath(DirectoryList::VAR_DIR)
            . '/qliro-e2e/lose-capture-' . $request->getOrderId();

        if (!is_file($file)) {
            return $proceed($request, $storeId);
        }

        unlink($file);
        $result = $proceed($request, $storeId);
        file_put_contents($file . '.result.json', json_encode([
            'requestId' => $request->getRequestId(),
            'transactionId' => $result ? (int)$result->getPaymentTransactionId() : null,
            'status' => $result ? $result->getStatus() : null,
        ]));

        $timeout = new ConnectException(
            'cURL error 28: Operation timed out',
            new Request('POST', 'checkout/adminapi/v2/MarkItemsAsShipped')
        );

        throw new ClientException(
            __('Request to Qliro One has failed.'),
            new TerminalException($timeout->getMessage(), 0, $timeout)
        );
    }
}
