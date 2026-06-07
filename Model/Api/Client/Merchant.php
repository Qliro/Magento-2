<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Api\Client;

use GuzzleHttp\Exception\RequestException;
use Magento\Framework\Serialize\Serializer\Json;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Model\Api\Client\Exception\MerchantApiException;
use Qliro\QliroOne\Model\Api\Client\Exception\ClientException;
use Qliro\QliroOne\Model\Api\Client\Exception\OrderAlreadyExistsException;
use Qliro\QliroOne\Model\Api\Client\Exception\OrderExpiredException;
use Qliro\QliroOne\Model\Api\Service;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * Class Merchant
 */
class Merchant implements MerchantInterface
{
    /**
     * Class constructor
     *
     * @param Json                  $json
     * @param Service               $service
     * @param LogManager            $logManager
     */
    public function __construct(
        private readonly Json       $json,
        private readonly Service    $service,
        private readonly LogManager $logManager
    ) {
    }

    /**
     * Perform QliroOne order creation
     *
     * @param array $payload
     * @return int|null
     * @throws ClientException
     */
    public function createOrder(array $payload): ?int
    {
        $this->logManager->addTag('sensitive');

        $qliroOrderId = null;

        try {
            $response = $this->service->post('checkout/merchantapi/orders', $payload);
            $qliroOrderId = $response['OrderId'] ?? null;
        } catch (\Exception $exception) {
            $this->logManager->removeTag('sensitive');
            $this->handleExceptions($exception);
        }

        $this->logManager->removeTag('sensitive');

        return $qliroOrderId;
    }

    /**
     * @inheriDoc
     */
    public function getOrder(int $qliroOrderId): array
    {
        $data = [];
        $this->logManager->addTag('sensitive');

        try {
            $data = $this->service->get('checkout/merchantapi/orders/{OrderId}', ['OrderId' => $qliroOrderId]);
        } catch (\Exception $exception) {
            $this->logManager->removeTag('sensitive');
            $this->handleExceptions($exception);
        }

        $this->logManager->removeTag('sensitive');

        return $data;
    }

    /**
     * @inheriDoc
     */
    public function updateOrder(int $qliroOrderId, array $payload): int
    {
        $this->logManager->addTag('sensitive');

        $payload['OrderId'] = $qliroOrderId;

        try {
            $this->service->put('checkout/merchantapi/orders/{OrderId}', $payload);
        } catch (\Exception $exception) {
            $this->logManager->removeTag('sensitive');
            $this->handleExceptions($exception);
        }

        $this->logManager->removeTag('sensitive');

        return $qliroOrderId;
    }

    /**
     * Handle exceptions that come from the API response
     *
     * @param \Exception $exception
     * @throws ClientException
     */
    private function handleExceptions(\Exception $exception): never
    {
        if ($exception instanceof RequestException) {
            $data = $this->json->unserialize($exception->getResponse()->getBody());

            if (isset($data['ErrorCode']) && isset($data['ErrorMessage'])) {
                if (!($exception instanceof TerminalException)) {
                    $this->logManager->critical($exception, ['extra' => $data]);
                }

                // Surface ORDER_EXPIRED as a specific exception, so callers can react —
                // typically by deactivating the dead link and creating a fresh Qliro order
                // (Qliro requires a NEW unique merchant reference for the recreation).
                if ($data['ErrorCode'] === 'ORDER_EXPIRED') {
                    throw new OrderExpiredException(
                        __('Error [%1]: %2', $data['ErrorCode'], $data['ErrorMessage'])
                    );
                }

                if ($data['ErrorCode'] === 'ORDER_ALREADY_EXISTS') {
                    throw new OrderAlreadyExistsException(
                        __('Error [%1]: %2', $data['ErrorCode'], $data['ErrorMessage'])
                    );
                }

                throw new MerchantApiException(
                    __('Error [%1]: %2', $data['ErrorCode'], $data['ErrorMessage'])
                );
            }
        }

        if (!($exception instanceof TerminalException)) {
            $this->logManager->critical($exception);
        }

        throw new ClientException(__('Request to Qliro One has failed.'), $exception);
    }
}
