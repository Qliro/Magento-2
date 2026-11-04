<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Api\Client;

use GuzzleHttp\Exception\RequestException;
use Magento\Framework\Serialize\Serializer\Json;
use Qliro\QliroOne\Api\Data\QliroOrderCreateRequestInterface;
use Qliro\QliroOne\Api\Data\QliroOrderInterface;
use Qliro\QliroOne\Api\Data\QliroOrderInterfaceFactory;
use Qliro\QliroOne\Api\Data\QliroOrderUpdateRequestInterface;
use Qliro\QliroOne\Model\Api\Client\Exception\MerchantApiException;
use Qliro\QliroOne\Model\Api\Client\Exception\ClientException;
use Qliro\QliroOne\Model\Api\Service;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Logger\Redactor;

/**
 * Merchant API client class
 */
class Merchant implements \Qliro\QliroOne\Api\Client\MerchantInterface
{
    /**
     * What Qliro answers for a card it already deleted or never had
     */
    private const SAVED_CARD_GONE = [
        'MERCHANT_SAVED_CREDIT_CARD_REMOVED',
        'MERCHANT_SAVED_CREDIT_CARD_ID_NOT_EXIST',
    ];

    /**
     * @var \Qliro\QliroOne\Model\Api\Service
     */
    private $service;

    /**
     * @var \Qliro\QliroOne\Model\Config
     */
    private $config;

    /**
     * @var \Qliro\QliroOne\Model\ContainerMapper
     */
    private $containerMapper;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    private $json;

    /**
     * @var \Qliro\QliroOne\Api\Data\QliroOrderInterfaceFactory
     */
    private $qliroOrderFactory;

    /**
     * @var \Qliro\QliroOne\Model\Logger\Manager
     */
    private $logManager;

    /**
     * Inject dependencies
     *
     * @param \Qliro\QliroOne\Model\Api\Service $service
     * @param \Qliro\QliroOne\Model\Config $config
     * @param \Magento\Framework\Serialize\Serializer\Json $json
     * @param \Qliro\QliroOne\Model\ContainerMapper $containerMapper
     * @param \Qliro\QliroOne\Api\Data\QliroOrderInterfaceFactory $qliroOrderFactory
     * @param \Qliro\QliroOne\Model\Logger\Manager $logManager
     */
    public function __construct(
        Service $service,
        Config $config,
        Json $json,
        ContainerMapper $containerMapper,
        QliroOrderInterfaceFactory $qliroOrderFactory,
        LogManager $logManager
    ) {
        $this->service = $service;
        $this->config = $config;
        $this->containerMapper = $containerMapper;
        $this->json = $json;
        $this->qliroOrderFactory = $qliroOrderFactory;
        $this->logManager = $logManager;
    }

    /**
     * Perform QliroOne order creation
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderCreateRequestInterface $qliroOrderCreateRequest
     * @return int
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    public function createOrder(QliroOrderCreateRequestInterface $qliroOrderCreateRequest)
    {
        $qliroOrderId = null;
        $payload = $this->containerMapper->toArray($qliroOrderCreateRequest);

        try {
            // The customer is in the checkout waiting for the widget this creates
            $response = $this->service->post(
                'checkout/merchantapi/orders',
                $payload,
                null,
                Config::API_PROFILE_INTERACTIVE
            );
            $qliroOrderId = $response['OrderId'] ?? null;
        } catch (\Exception $exception) {
            $this->handleExceptions($exception);
        }

        return $qliroOrderId;
    }

    /**
     * Get QliroOne order by its Qliro Order ID
     *
     * @param int $qliroOrderId
     * @param string|null $profile Which timeouts to call with, see Config::API_PROFILE_*
     * @return \Qliro\QliroOne\Api\Data\QliroOrderInterface
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    public function getOrder($qliroOrderId, $profile = null)
    {
        /** @var QliroOrderInterface $qliroOrder */
        $qliroOrder = $this->qliroOrderFactory->create();

        try {
            $response = $this->service->get(
                'checkout/merchantapi/orders/{OrderId}',
                ['OrderId' => $qliroOrderId],
                null,
                $profile ?: Config::API_PROFILE_INTERACTIVE
            );
            $this->containerMapper->fromArray($response, $qliroOrder);
        } catch (\Exception $exception) {
            $this->handleExceptions($exception);
        }

        return $qliroOrder;
    }

    /**
     * Update QliroOne order
     *
     * @param int $qliroOrderId
     * @param \Qliro\QliroOne\Api\Data\QliroOrderUpdateRequestInterface $qliroOrderUpdateRequest
     * @return int
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    public function updateOrder($qliroOrderId, QliroOrderUpdateRequestInterface $qliroOrderUpdateRequest)
    {
        $payload = $this->containerMapper->toArray($qliroOrderUpdateRequest);
        $payload['OrderId'] = $qliroOrderId;

        try {
            // The customer is watching the widget this updates
            $response = $this->service->put(
                'checkout/merchantapi/orders/{OrderId}',
                $payload,
                null,
                Config::API_PROFILE_INTERACTIVE
            );
        } catch (\Exception $exception) {
            $this->handleExceptions($exception);
        }

        return $qliroOrderId;
    }

    /**
     * Delete a saved card token at Qliro
     *
     * @param string $savedCreditCardId
     * @param int|null $storeId
     * @return bool False when Qliro no longer had the card
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    public function removeSavedCreditCard(string $savedCreditCardId, $storeId = null): bool
    {
        try {
            // The customer waits for the answer on the account page
            $this->service->delete(
                'checkout/merchantapi/RemoveMerchantSavedCreditCard',
                ['MerchantSavedCreditCardId' => $savedCreditCardId],
                $storeId,
                Config::API_PROFILE_INTERACTIVE
            );

            return true;
        } catch (TerminalException $exception) {
            if (in_array($exception->getQliroErrorCode(), self::SAVED_CARD_GONE, true)) {
                return false;
            }
            $this->handleExceptions($exception);
        } catch (\Exception $exception) {
            $this->handleExceptions($exception);
        }

        return false;
    }

    /**
     * Handle exceptions that come from the API response
     *
     * @param \Exception $exception
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    private function handleExceptions(\Exception $exception)
    {
        // Qliro's refusal quotes the request back and the exception message carries the response
        // body, so every line this method writes is a payload. The tag covers all of them, and the
        // finally takes it back on the way out, which is always by a throw
        $this->logManager->addTag(Redactor::TAG_SENSITIVE);

        try {
            $this->handleExceptionsUntagged($exception);
        } finally {
            $this->logManager->removeTag(Redactor::TAG_SENSITIVE);
        }
    }

    /**
     * @param \Exception $exception
     * @return void
     * @throws \Exception
     */
    private function handleExceptionsUntagged(\Exception $exception)
    {
        if ($exception instanceof RequestException) {
            $data = $this->json->unserialize($exception->getResponse()->getBody());

            if (isset($data['ErrorCode']) && isset($data['ErrorMessage'])) {
                if (!($exception instanceof TerminalException)) {
                    $this->logManager->critical($exception, ['extra' => $data]);
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
