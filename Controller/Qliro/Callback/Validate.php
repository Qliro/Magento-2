<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Controller\Qliro\Callback;

use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Qliro\QliroOne\Api\Data\UpdateShippingMethodNotificationInterfaceFactory;
use Qliro\QliroOne\Api\Data\ValidateOrderNotificationInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderResponseInterface;
use Qliro\QliroOne\Api\ManagementInterface;
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\Security\CallbackToken;

/**
 * Validate callback controller action
 */
class Validate extends \Magento\Framework\App\Action\Action
{
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
     * @var Manager
     */
    private $logManager;

    /**
     * Inject dependencies
     *
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Qliro\QliroOne\Model\Config $qliroConfig
     * @param \Qliro\QliroOne\Api\ManagementInterface $qliroManagement
     * @param \Qliro\QliroOne\Model\ContainerMapper $containerMapper
     * @param \Qliro\QliroOne\Helper\Data $dataHelper
     * @param \Qliro\QliroOne\Model\Security\CallbackToken $callbackToken
     * @param \Qliro\QliroOne\Model\Logger\Manager $logManager
     */
    public function __construct(
        Context $context,
        Config $qliroConfig,
        ManagementInterface $qliroManagement,
        ContainerMapper $containerMapper,
        Data $dataHelper,
        CallbackToken $callbackToken,
        Manager $logManager
    ) {
        parent::__construct($context);

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
        $this->logManager->info('Notification Validate start');

        if (!$this->qliroConfig->isActive()) {
            return $this->dataHelper->sendPreparedPayload(
                ['error' => ValidateOrderResponseInterface::REASON_OTHER],
                400,
                null,
                'CALLBACK:VALIDATE:ERROR_INACTIVE'
            );
        }

        /** @var \Magento\Framework\App\Request\Http $request */
        $request = $this->getRequest();

        if (!$this->callbackToken->verifyToken($request->getParam('token'))) {
            return $this->dataHelper->sendPreparedPayload(
                ['error' => ValidateOrderResponseInterface::REASON_OTHER],
                400,
                null,
                'CALLBACK:VALIDATE:ERROR_TOKEN'
            );
        }

        $payload = $this->dataHelper->readPreparedPayload($request, 'CALLBACK:VALIDATE');

        /** @var \Qliro\QliroOne\Api\Data\ValidateOrderNotificationInterface $validateContainer */
        $validateContainer = $this->containerMapper->fromArray(
            $payload,
            ValidateOrderNotificationInterface::class
        );

        if (empty($validateContainer->getOrderId())) {
            $this->logManager->warning(
                'Callback payload carries no OrderId, refusing to look up a link with an empty value. '
                . 'The request body was probably lost on the way, check the callback URL for redirects.'
            );

            return $this->dataHelper->sendPreparedPayload(
                ['error' => ValidateOrderResponseInterface::REASON_OTHER],
                400,
                null,
                'CALLBACK:VALIDATE:ERROR_NO_ORDER_ID'
            );
        }

        $responseContainer = $this->qliroManagement->validateQliroOrder($validateContainer);

        $response = $this->dataHelper->sendPreparedPayload(
            $responseContainer,
            $responseContainer->getDeclineReason() ? 400 : 200,
            null,
            'CALLBACK:VALIDATE'
        );

        $this->logManager->info('Notification Validate done in {duration} seconds', ['duration' => \microtime(true) - $start]);

        return $response;
    }
}
