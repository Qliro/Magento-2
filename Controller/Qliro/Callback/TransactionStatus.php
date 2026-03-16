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
use Qliro\QliroOne\Api\Admin\OrderServiceInterface as OrderService;
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Security\CallbackToken;

/**
 * Management status push callback controller action
 */
class TransactionStatus implements HttpPostActionInterface
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
     * @throws \Exception
     */
    public function execute(): ResultInterface|ResponseInterface
    {
        $start = microtime(true);
        $this->logManager->info('Notification TransactionStatus start');

        if (!$this->qliroConfig->isActive()) {
            return $this->dataHelper->sendPreparedPayload(
                [ 'CallbackResponse' => 'NotificationsDisabled' ],
                400,
                null,
                'CALLBACK:MANAGEMENT_STATUS:ERROR_INACTIVE'
            );
        }

        if (!$this->callbackToken->verifyToken($this->request->getParam('token'))) {
            return $this->dataHelper->sendPreparedPayload(
                [ 'CallbackResponse' => 'AuthenticateError' ],
                400,
                null,
                'CALLBACK:MANAGEMENT_STATUS:ERROR_TOKEN'
            );
        }

        $payload = $this->dataHelper->readPreparedPayload($this->request, 'CALLBACK:MANAGEMENT_STATUS');

        $responseContainer = $this->orderService->handleTransactionStatus($payload);

        $response = $this->dataHelper->sendPreparedPayload(
            $responseContainer,
            ($responseContainer['CallbackResponse'] ?? '') === 'Received' ? 200 : 500,
            null,
            'CALLBACK:MANAGEMENT_STATUS'
        );

        $this->logManager->info(
            'Notification TransactionStatus done in {duration} seconds',
            ['duration' => \microtime(true) - $start]
        );

        return $response;
    }
}
