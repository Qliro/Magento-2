<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Controller\Qliro\Callback;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Qliro\QliroOne\Api\Checkout\OrderServiceInterface as OrderService;
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Security\CallbackToken;

/**
 * Class ShippingMethods
 */
class ShippingMethods implements HttpPostActionInterface
{
    /**
     * Class constructor
     *
     * @param HttpRequest              $request
     * @param OrderService             $orderService
     * @param Config                   $qliroConfig
     * @param Data                     $dataHelper
     * @param LogManager               $logManager
     * @param CallbackToken            $callbackToken
     */
    public function __construct(
        private readonly HttpRequest   $request,
        private readonly OrderService  $orderService,
        private readonly Config        $qliroConfig,
        private readonly Data          $dataHelper,
        private readonly LogManager    $logManager,
        private readonly CallbackToken $callbackToken
    ) {
    }

    /**
     * Dispatch request
     *
     * @return ResultInterface|ResponseInterface
     */
    public function execute(): ResultInterface|ResponseInterface
    {
        $start = \microtime(true);
        $this->logManager->info('Notification ShippingMethods start');

        if (!$this->qliroConfig->isActive()) {
            return $this->dataHelper->sendPreparedPayload(
                [ 'error' => 'PostalCode' ],
                400,
                null,
                'CALLBACK:SHIPPING_METHODS:ERROR_INACTIVE'
            );
        }

        if (!$this->callbackToken->verifyToken($this->request->getParam('token'))) {
            return $this->dataHelper->sendPreparedPayload(
                [ 'error' => 'PostalCode' ],
                400,
                null,
                'CALLBACK:SHIPPING_METHODS:ERROR_TOKEN'
            );
        }

        $payload = $this->dataHelper->readPreparedPayload($this->request, 'CALLBACK:SHIPPING_METHODS');

        $responseContainer = $this->orderService->getShippingMethods($payload);

        $response = $this->dataHelper->sendPreparedPayload(
            $responseContainer,
            !empty($responseContainer['DeclineReason']) ? 400 : 200,
            null,
            'CALLBACK:SHIPPING_METHODS'
        );

        $this->logManager->info(
            'Notification ShippingMethods done in {duration} seconds',
            ['duration' => \microtime(true) - $start]
        );

        return $response;
    }
}
