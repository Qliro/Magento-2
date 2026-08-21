<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Controller\Qliro\Callback;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\ResponseInterface;
use Qliro\QliroOne\Api\Data\MerchantNotificationInterface;
use Qliro\QliroOne\Api\Data\MerchantNotificationResponseInterface;
use Qliro\QliroOne\Api\ManagementInterface;
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Security\CallbackToken;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * Merchant push callback controller action
 */
class MerchantNotification implements HttpPostActionInterface
{
    /**
     * @var \Magento\Framework\App\Request\Http
     */
    private Http $request;

    /**
     * @var \Qliro\QliroOne\Model\Config
     */
    private $qliroConfig;

    /**
     * @var \Qliro\QliroOne\Api\ManagementInterface
     */
    private $qliroManagement;

    /**
     * @var \Qliro\QliroOne\Model\ContainerMapper
     */
    private $containerMapper;

    /**
     * @var \Qliro\QliroOne\Helper\Data
     */
    private Data $dataHelper;

    /**
     * @var \Qliro\QliroOne\Model\Security\CallbackToken
     */
    private CallbackToken $callbackToken;

    /**
     * @var \Qliro\QliroOne\Model\Logger\Manager
     */
    private $logManager;

    public function __construct(
        Http $request,
        Config $qliroConfig,
        ManagementInterface $qliroManagement,
        ContainerMapper $containerMapper,
        Data $dataHelper,
        CallbackToken $callbackToken,
        LogManager $logManager
    ) {
        $this->request = $request;
        $this->qliroConfig = $qliroConfig;
        $this->qliroManagement = $qliroManagement;
        $this->containerMapper = $containerMapper;
        $this->dataHelper = $dataHelper;
        $this->callbackToken = $callbackToken;
        $this->logManager = $logManager;
    }

    /**
     * Dispatch request
     *
     * @return \Magento\Framework\Controller\ResultInterface|ResponseInterface
     */
    public function execute()
    {
        $start = \microtime(true);
        $this->logManager->info('MerchantNotification Callback start');

        if (!$this->qliroConfig->isActive()) {
            return $this->dataHelper->sendPreparedPayload(
                [
                    MerchantNotificationResponseInterface::CALLBACK_RESPONSE =>
                        MerchantNotificationResponseInterface::RESPONSE_NOTIFICATIONS_DISABLED
                ],
                400,
                null,
                'CALLBACK:MERCHANT_NOTIFICATION:ERROR_INACTIVE'
            );
        }

        if (!$this->callbackToken->verifyToken($this->request->getParam('token'))) {
            return $this->dataHelper->sendPreparedPayload(
                [
                    MerchantNotificationResponseInterface::CALLBACK_RESPONSE =>
                        MerchantNotificationResponseInterface::RESPONSE_AUTHENTICATE_ERROR
                ],
                400,
                null,
                'CALLBACK:MERCHANT_NOTIFICATION:ERROR_TOKEN'
            );
        }

        $payload = $this->dataHelper->readPreparedPayload($this->request, 'CALLBACK:MERCHANT_NOTIFICATION');

        /** @var \Qliro\QliroOne\Api\Data\MerchantNotificationInterface $updateContainer */
        $updateContainer = $this->containerMapper->fromArray(
            $payload,
            MerchantNotificationInterface::class
        );

        if (empty($updateContainer->getOrderId())) {
            $this->logManager->warning(
                'Callback payload carries no OrderId, refusing to look up a link with an empty value. '
                . 'The request body was probably lost on the way, check the callback URL for redirects.'
            );

            return $this->dataHelper->sendPreparedPayload(
                [
                    MerchantNotificationResponseInterface::CALLBACK_RESPONSE =>
                        MerchantNotificationResponseInterface::RESPONSE_ORDER_NOT_FOUND
                ],
                400,
                null,
                'CALLBACK:MERCHANT_NOTIFICATION:ERROR_NO_ORDER_ID'
            );
        }

        $responseContainer = $this->qliroManagement->merchantNotification($updateContainer);

        $response = $this->dataHelper->sendPreparedPayload(
            $responseContainer,
            $responseContainer->getCallbackResponseCode(),
            null,
            'CALLBACK:MERCHANT_NOTIFICATION'
        );

        $this->logManager->info(
            'MerchantNotification Callback done in {duration} seconds',
            ['duration' => \microtime(true) - $start]
        );

        return $response;
    }
}
