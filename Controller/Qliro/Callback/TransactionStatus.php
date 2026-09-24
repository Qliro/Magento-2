<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Controller\Qliro\Callback;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\ResponseInterface;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusInterface;
use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusResponseInterface as OmStatusResponse;
use Qliro\QliroOne\Api\ManagementInterface;
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Security\CallbackToken;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * Management status push callback controller action
 */
class TransactionStatus implements HttpPostActionInterface, CsrfAwareActionInterface
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
    private $dataHelper;

    /**
     * @var \Qliro\QliroOne\Model\Security\CallbackToken
     */
    private $callbackToken;

    /**
     * @var \Qliro\QliroOne\Model\Logger\Manager
     */
    private $logManager;

    /**
     * Inject dependencies
     *
     * @param \Magento\Framework\App\Request\Http $request
     * @param \Qliro\QliroOne\Model\Config $qliroConfig
     * @param \Qliro\QliroOne\Api\ManagementInterface $qliroManagement
     * @param \Qliro\QliroOne\Model\ContainerMapper $containerMapper
     * @param \Qliro\QliroOne\Helper\Data $dataHelper
     * @param \Qliro\QliroOne\Model\Security\CallbackToken $callbackToken
     * @param \Qliro\QliroOne\Model\Logger\Manager $logManager
     */
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
     * @throws \Exception
     */
    public function execute()
    {
        $start = \microtime(true);
        $this->logManager->info('Notification TransactionStatus start');

        if (!$this->qliroConfig->isActive()) {
            return $this->dataHelper->sendPreparedPayload(
                [OmStatusResponse::CALLBACK_RESPONSE => OmStatusResponse::RESPONSE_NOTIFICATIONS_DISABLED],
                400,
                null,
                'CALLBACK:MANAGEMENT_STATUS:ERROR_INACTIVE'
            );
        }

        /** @var \Magento\Framework\App\Request\Http $request */
        $request = $this->request;

        if (!$this->callbackToken->verifyToken($request->getParam('token'))) {
            return $this->dataHelper->sendPreparedPayload(
                [OmStatusResponse::CALLBACK_RESPONSE => OmStatusResponse::RESPONSE_AUTHENTICATE_ERROR],
                400,
                null,
                'CALLBACK:MANAGEMENT_STATUS:ERROR_TOKEN'
            );
        }

        $payload = $this->dataHelper->readPreparedPayload($request, 'CALLBACK:MANAGEMENT_STATUS');

        /** @var \Qliro\QliroOne\Api\Data\QliroOrderManagementStatusInterface $updateContainer */
        $updateContainer = $this->containerMapper->fromArray(
            $payload,
            QliroOrderManagementStatusInterface::class
        );

        if (empty($updateContainer->getOrderId())) {
            $this->logManager->warning(
                'Callback payload carries no OrderId, refusing to look up a link with an empty value. '
                . 'The request body was probably lost on the way, check the callback URL for redirects.'
            );

            return $this->dataHelper->sendPreparedPayload(
                [OmStatusResponse::CALLBACK_RESPONSE => OmStatusResponse::RESPONSE_ORDER_NOT_FOUND],
                400,
                null,
                'CALLBACK:MANAGEMENT_STATUS:ERROR_NO_ORDER_ID'
            );
        }

        $responseContainer = $this->qliroManagement->handleTransactionStatus($updateContainer);

        $response = $this->dataHelper->sendPreparedPayload(
            $responseContainer,
            $responseContainer->getCallbackResponse() == OmStatusResponse::RESPONSE_RECEIVED ? 200 : 500,
            null,
            'CALLBACK:MANAGEMENT_STATUS'
        );

        $this->logManager->info('Notification TransactionStatus done in {duration} seconds', ['duration' => \microtime(true) - $start]);

        return $response;
    }

    /**
     * Qliro calls this server to server, it carries no form key and proves itself with the token
     *
     * @param RequestInterface $request
     * @return InvalidRequestException|null
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * Accept the callback without a form key, the token is checked in execute()
     *
     * @param RequestInterface $request
     * @return bool|null
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
