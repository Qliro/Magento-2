<?php declare(strict_types=1);
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Controller\Qliro\Callback;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\ResponseInterface;
use Qliro\QliroOne\Api\Data\MerchantSavedCreditCardNotificationInterface;
use Qliro\QliroOne\Api\Data\MerchantSavedCreditCardResponseInterface;
use Qliro\QliroOne\Api\ManagementInterface;
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Security\CallbackToken;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * Saved Credit Card callback controller action
 */
class SavedCreditCard implements HttpPostActionInterface
{
    /**
     * @var \Magento\Framework\App\Request\Http
     */
    private Http $request;

    /**
     * @var \Qliro\QliroOne\Model\Config
     */
    private Config $qliroConfig;

    /**
     * @var \Qliro\QliroOne\Api\ManagementInterface
     */
    private ManagementInterface $qliroManagement;

    /**
     * @var \Qliro\QliroOne\Model\ContainerMapper
     */
    private ContainerMapper $containerMapper;

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
    private LogManager $logManager;

    /**
     * Inject dependencies
     *
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
     */
    public function execute()
    {
        $start = \microtime(true);
        $this->logManager->info('Notification SavedCreditCard start');

        if (!$this->qliroConfig->isActive()) {
            return $this->dataHelper->sendPreparedPayload(
                [
                    MerchantSavedCreditCardResponseInterface::CALLBACK_RESPONSE =>
                        MerchantSavedCreditCardResponseInterface::RESPONSE_NOTIFICATIONS_DISABLED
                ],
                400,
                null,
                'CALLBACK:MERCHANT_SAVED_CREDIT_CARD:ERROR_INACTIVE'
            );
        }

        if (!$this->callbackToken->verifyToken($this->request->getParam('token'))) {
            return $this->dataHelper->sendPreparedPayload(
                [
                    MerchantSavedCreditCardResponseInterface::CALLBACK_RESPONSE =>
                        MerchantSavedCreditCardResponseInterface::RESPONSE_AUTHENTICATE_ERROR
                ],
                400,
                null,
                'CALLBACK:MERCHANT_SAVED_CREDIT_CARD:ERROR_TOKEN'
            );
        }

        $payload = $this->dataHelper->readPreparedPayload(
            $this->request,
            'CALLBACK:MERCHANT_SAVED_CREDIT_CARD',
            ['CardBin', 'CardLast4Digits', 'CardExpiryYear', 'CardExpiryMonth', 'CardBrandName']
        );

        /** @var \Qliro\QliroOne\Api\Data\MerchantSavedCreditCardNotificationInterface $updateContainer */
        $updateContainer = $this->containerMapper->fromArray(
            $payload,
            MerchantSavedCreditCardNotificationInterface::class
        );

        $responseContainer = $this->qliroManagement->updateOrderSavedCreditCard($updateContainer);

        $response = $this->dataHelper->sendPreparedPayload(
            $responseContainer,
            $responseContainer->getCallbackResponseCode(),
            null,
            'CALLBACK:MERCHANT_SAVED_CREDIT_CARD'
        );

        $this->logManager->info('Notification SavedCreditCard done in {duration} seconds', ['duration' => \microtime(true) - $start]);

        return $response;
    }
}
