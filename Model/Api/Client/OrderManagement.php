<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Api\Client;

use GuzzleHttp\Exception\RequestException;
use Magento\Framework\Serialize\Serializer\Json;
use Qliro\QliroOne\Api\Data\AdminCancelOrderRequestInterface;
use Qliro\QliroOne\Api\Data\AdminCreateMerchantPaymentResponseInterface;
use Qliro\QliroOne\Api\Data\AdminMarkItemsAsShippedRequestInterface;
use Qliro\QliroOne\Api\Data\AdminOrderInterface;
use Qliro\QliroOne\Api\Data\AdminOrderPaymentTransactionInterface;
use Qliro\QliroOne\Api\Data\AdminReturnWithItemsRequestInterface;
use Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface;
use Qliro\QliroOne\Api\Data\AdminUpdateMerchantReferenceRequestInterface;
use Qliro\QliroOne\Api\Data\CheckoutStatusInterface;
use Qliro\QliroOne\Api\Data\QliroOrderInterfaceFactory;
use Qliro\QliroOne\Model\Api\Client\Exception\ClientException;
use Qliro\QliroOne\Model\Api\Client\Exception\OrderManagementApiException;
use Qliro\QliroOne\Model\Api\Service;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Magento\Framework\DataObject\IdentityGeneratorInterface;

/**
 * Order Management API client class
 */
class OrderManagement implements \Qliro\QliroOne\Api\Client\OrderManagementInterface
{
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
     * @var \Magento\Framework\DataObject\IdentityGeneratorInterface
     */
    private IdentityGeneratorInterface $idGenerator;

    /**
     * Inject dependencies
     *
     * @param \Qliro\QliroOne\Model\Api\Service $service
     * @param \Qliro\QliroOne\Model\Config $config
     * @param \Magento\Framework\Serialize\Serializer\Json $json
     * @param \Qliro\QliroOne\Model\ContainerMapper $containerMapper
     * @param \Qliro\QliroOne\Api\Data\QliroOrderInterfaceFactory $qliroOrderFactory
     * @param \Qliro\QliroOne\Model\Logger\Manager $logManager
     * @param \Magento\Framework\DataObject\IdentityGeneratorInterface $idGenerator
     */
    public function __construct(
        Service $service,
        Config $config,
        Json $json,
        ContainerMapper $containerMapper,
        QliroOrderInterfaceFactory $qliroOrderFactory,
        LogManager $logManager,
        IdentityGeneratorInterface $idGenerator
    ) {
        $this->service = $service;
        $this->config = $config;
        $this->containerMapper = $containerMapper;
        $this->json = $json;
        $this->qliroOrderFactory = $qliroOrderFactory;
        $this->logManager = $logManager;
        $this->idGenerator = $idGenerator;
    }

    /**
     * Get admin QliroOne order by its Qliro Order ID
     *
     * @param int $qliroOrderId
     * @return \Qliro\QliroOne\Api\Data\AdminOrderInterface
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    public function getOrder($qliroOrderId)
    {
        $container = null;

        try {
            $response = $this->service->get('checkout/adminapi/v2/orders/{OrderId}', ['OrderId' => $qliroOrderId]);

            /** @var \Qliro\QliroOne\Api\Data\AdminOrderInterface $container */
            $container = $this->containerMapper->fromArray($response, AdminOrderInterface::class);
        } catch (\Exception $exception) {
            $this->handleExceptions($exception);
        }

        return $container;
    }

    /**
     * Send a "Mark items as shipped" request
     *
     * @param \Qliro\QliroOne\Api\Data\AdminMarkItemsAsShippedRequestInterface $request
     * @param int|null $storeId
     * @return \Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    public function markItemsAsShipped(AdminMarkItemsAsShippedRequestInterface $request, $storeId = null)
    {
        $container = null;
        $request->setRequestId($this->idGenerator->generateId());

        try {
            $payload = $this->containerMapper->toArray($request);
            $response = $this->service->post('checkout/adminapi/v2/MarkItemsAsShipped', $payload, $storeId);
            $paymentTransactions = $response['PaymentTransactions'] ?? [];

            /** @var \Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface $container */
            $container = $this->containerMapper->fromArray($paymentTransactions[0] ?? [], AdminTransactionResponseInterface::class);
        } catch (\Exception $exception) {
            $this->handleExceptions($exception);
        }

        return $container;
    }

    /**
     * Cancel admin QliroOne order
     *
     * @param \Qliro\QliroOne\Api\Data\AdminCancelOrderRequestInterface $request
     * @param int|null $storeId
     * @return \Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    public function cancelOrder(AdminCancelOrderRequestInterface $request, $storeId = null)
    {
        $container = null;
        $request->setRequestId($this->idGenerator->generateId());

        try {
            $payload = $this->containerMapper->toArray($request);
            $response = $this->service->post('checkout/adminapi/v2/cancelOrder', $payload, $storeId);
            $paymentTransactions = $response['PaymentTransactions'] ?? [];

            /** @var \Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface $container */
            $container = $this->containerMapper->fromArray($paymentTransactions[0] ?? [], AdminTransactionResponseInterface::class);
        } catch (\Exception $exception) {
            // Workaround for having cancelOrder NOT throwing exception in case of success
            if ($exception instanceof RequestException) {
                $data = $this->json->unserialize($exception->getResponse()->getBody());

                $errorCode = $data['ErrorCode'] ?? null;

                if ($errorCode === 'ORDER_HAS_BEEN_CANCELLED') {
                    /** @var \Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface $container */
                    $container = $this->containerMapper->fromArray(
                        ['Status' => CheckoutStatusInterface::STATUS_REFUSED],
                        AdminTransactionResponseInterface::class
                    );

                    return $container;
                }
            }

            // Otherwise, handle exceptions as usual
            $this->handleExceptions($exception);
        }

        return $container;
    }

    /**
     * Update QliroOne order merchant reference
     *
     * @param \Qliro\QliroOne\Api\Data\AdminUpdateMerchantReferenceRequestInterface $request
     * @param int|null $storeId
     * @return \Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    public function updateMerchantReference(AdminUpdateMerchantReferenceRequestInterface $request, $storeId = null)
    {
        $container = null;
        $request->setRequestId($this->idGenerator->generateId());

        try {
            $payload = $this->containerMapper->toArray($request);
            $response = $this->service->post('checkout/adminapi/v2/updatemerchantreference', $payload, $storeId);

            /** @var \Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface $container */
            $container = $this->containerMapper->fromArray($response, AdminTransactionResponseInterface::class);
        } catch (\Exception $exception) {
            /**
             * This function is called inside a notification from Qliro. That notification should just respond with ok
             * Unless something is very wrong. What the call to updatemerchantreference responds with should NOT
             * make any difference in what that notification should respond! The return from this function only logs
             * the transactionId....
             * @todo This needs to be fixed properly once we can debug notifications
             */

            // Workaround for having updateMerchantReference NOT throwing exception in case of success
//            if ($exception instanceof RequestException) {
//                $data = $this->json->unserialize($exception->getResponse()->getBody());
//
//                $errorCode = $data['ErrorCode'] ?? null;
//
//                if ($errorCode === 'ORDER_HAS_BEEN_CANCELLED') {
//                    /** @var \Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface $container */
//                    $container = $this->containerMapper->fromArray(
//                        ['Status' => CheckoutStatusInterface::STATUS_REFUSED],
//                        AdminTransactionResponseInterface::class
//                    );
//
//                    return $container;
//                }
//            }
//
//            $this->handleExceptions($exception);
        }

        return $container;
    }

    /**
     * Make a call "Return with items"
     *
     * @param \Qliro\QliroOne\Api\Data\AdminReturnWithItemsRequestInterface $request
     * @param int|null $storeId
     * @return \Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    public function returnWithItems(AdminReturnWithItemsRequestInterface $request, $storeId = null)
    {
        $container = null;
        $request->setRequestId($this->idGenerator->generateId());

        try {
            $payload = [
                'RequestId' => $request->getRequestId(),
                'MerchantApiKey' => $request->getMerchantApiKey(),
                'Currency' => $request->getCurrency(),
                'OrderId' => $request->getOrderId(),
                'Returns' => [$request->getReturns()],
            ];

            $response = $this->service->post('checkout/adminapi/v2/returnitems', $payload, $storeId);
            $paymentTransactions = $response['PaymentTransactions'] ?? [];

            /** @var \Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface $container */
            $container = $this->containerMapper->fromArray( $paymentTransactions[0] ?? [], AdminTransactionResponseInterface::class);
        } catch (\Exception $exception) {
            $this->handleExceptions($exception);
        }

        return $container;
    }

    /**
     * Get admin QliroOne order payment transaction
     * @todo Not used?
     *
     * @param int $paymentTransactionId
     * @param int|null $storeId
     * @return \Qliro\QliroOne\Api\Data\AdminOrderPaymentTransactionInterface
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    public function getPaymentTransaction($paymentTransactionId, $storeId = null)
    {
        $container = null;

        try {
            $response = $this->service->get(
                'checkout/adminapi/v2/paymentTransactions/{PaymentTransactionId}',
                ['PaymentTransactionId' => $paymentTransactionId],
                $storeId
            );

            /** @var \Qliro\QliroOne\Api\Data\AdminOrderPaymentTransactionInterface $container */
            $container = $this->containerMapper->fromArray($response, AdminOrderPaymentTransactionInterface::class);
        } catch (\Exception $exception) {
            $this->handleExceptions($exception);
        }

        return $container;
    }

    /**
     * Retry a reversal payment
     *
     * @param int $paymentReference
     * @param int|null $storeId
     * @return \Qliro\QliroOne\Api\Data\AdminOrderPaymentTransactionInterface|null
     * @throws ClientException
     */
    public function retryReversalPayment($paymentReference, $storeId = null)
    {
        $container = null;

        try {
            $response = $this->service->post(
                'checkout/adminapi/v2/retryReversalPaymentTransaction',
                ['PaymentReference' => $paymentReference],
                $storeId
            );

            /** @var \Qliro\QliroOne\Api\Data\AdminOrderPaymentTransactionInterface $container */
            $container = $this->containerMapper->fromArray($response, AdminOrderPaymentTransactionInterface::class);
        } catch (\Exception $exception) {
            $this->handleExceptions($exception);
        }

        return $container;
    }

    /**
     * Create a Merchant Payment
     *
     * @param \Qliro\QliroOne\Api\Data\AdminCreateMerchantPaymentRequestInterface $request
     * @param integer|null $storeId
     * @return AdminCreateMerchantPaymentResponseInterface|null
     * @throws ClientException
     */
    public function createMerchantPayment(
        \Qliro\QliroOne\Api\Data\AdminCreateMerchantPaymentRequestInterface $request,
        ?int $storeId = null
    ): ?AdminCreateMerchantPaymentResponseInterface {
        $container = null;

        try {
            $request->setRequestId($this->idGenerator->generateId());
            $payload = $this->containerMapper->toArray($request);
            $response = $this->service->post(
                'checkout/adminapi/v2/merchantpayment',
                $payload,
                $storeId
            );

            /** @var \Qliro\QliroOne\Api\Data\AdminCreateMerchantPaymentResponseInterface $container */
            $container = $this->containerMapper->fromArray(
                $response,
                AdminCreateMerchantPaymentResponseInterface::class
            );
        } catch (\Exception $exception) {
            $this->handleExceptions($exception);
        }

        return $container;
    }

    /**
     * Handle exceptions that come from the API response
     *
     * @param \Exception $exception
     * @throws \Qliro\QliroOne\Model\Api\Client\Exception\ClientException
     */
    private function handleExceptions(\Exception $exception)
    {
        if ($exception instanceof RequestException) {
            $data = $this->json->unserialize($exception->getResponse()->getBody());

            if (isset($data['ErrorCode']) && isset($data['ErrorMessage'])) {
                if (!($exception instanceof TerminalException)) {
                    $this->logManager->critical($exception, ['extra' => $data]);
                }

                throw new OrderManagementApiException(
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
