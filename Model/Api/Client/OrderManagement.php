<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Api\Client;

use Magento\Framework\DataObject\IdentityGeneratorInterface as IdentityGenerator;
use Magento\Framework\Serialize\Serializer\Json;
use GuzzleHttp\Exception\RequestException;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\AdminCreateMerchantPaymentRequestInterface;
use Qliro\QliroOne\Model\Payload\PayloadConverter;
use Qliro\QliroOne\Api\Data\AdminCancelOrderRequestInterface;
use Qliro\QliroOne\Api\Data\AdminCreateMerchantPaymentResponseInterface;
use Qliro\QliroOne\Api\Data\AdminMarkItemsAsShippedRequestInterface as AdminMarkItemsAsShippedRequest;
use Qliro\QliroOne\Api\Data\AdminOrderInterface;
use Qliro\QliroOne\Api\Data\AdminOrderPaymentTransactionInterface;
use Qliro\QliroOne\Api\Data\AdminReturnWithItemsRequestInterface;
use Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface;
use Qliro\QliroOne\Api\Data\AdminUpdateMerchantReferenceRequestInterface;
use Qliro\QliroOne\Model\Api\Client\Exception\ClientException;
use Qliro\QliroOne\Model\Api\Client\Exception\OrderManagementApiException;
use Qliro\QliroOne\Model\Api\Service;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * Order Management API client class
 */
readonly class OrderManagement implements OrderManagementInterface
{
    /**
     * Class constructor
     *
     * @param Service             $service
     * @param Json                $json
     * @param PayloadConverter    $payloadConverter
     * @param LogManager          $logManager
     * @param IdentityGenerator   $idGenerator
     */
    public function __construct(
        private Service           $service,
        private Json              $json,
        private PayloadConverter  $payloadConverter,
        private LogManager        $logManager,
        private IdentityGenerator $idGenerator
    ) {
    }

    /**
     * @inheirtDoc
     */
    public function getOrder(int $qliroOrderId): AdminOrderInterface
    {
        $container = null;

        try {
            $response = $this->service->get('checkout/adminapi/v2/orders/{OrderId}', ['OrderId' => $qliroOrderId]);

            /** @var AdminOrderInterface $container */
            $container = $this->payloadConverter->fromArray($response, AdminOrderInterface::class);
        } catch (\Exception $exception) {
            $this->handleExceptions($exception);
        }

        return $container;
    }

    /**
     * @inheirtDoc
     */
    public function markItemsAsShipped(AdminMarkItemsAsShippedRequest $request, int|string|null $storeId = null): AdminTransactionResponseInterface
    {
        $container = null;
        $request->setRequestId($this->idGenerator->generateId());

        try {
            $payload = $this->payloadConverter->toArray($request);
            $response = $this->service->post('checkout/adminapi/v2/MarkItemsAsShipped', $payload, $storeId);
            $paymentTransactions = $response['PaymentTransactions'] ?? [];

            /** @var AdminTransactionResponseInterface $container */
            $container = $this->payloadConverter->fromArray($paymentTransactions[0] ?? [], AdminTransactionResponseInterface::class);
        } catch (\Exception $exception) {
            $this->handleExceptions($exception);
        }

        return $container;
    }

    /**
     * @inheirtDoc
     */
    public function cancelOrder(AdminCancelOrderRequestInterface $request, int|string|null $storeId = null): AdminTransactionResponseInterface
    {
        $container = null;
        $request->setRequestId($this->idGenerator->generateId());

        try {
            $payload = $this->payloadConverter->toArray($request);
            $response = $this->service->post('checkout/adminapi/v2/cancelOrder', $payload, $storeId);
            $paymentTransactions = $response['PaymentTransactions'] ?? [];

            /** @var AdminTransactionResponseInterface $container */
            $container = $this->payloadConverter->fromArray($paymentTransactions[0] ?? [], AdminTransactionResponseInterface::class);
        } catch (\Exception $exception) {
            // Workaround for having cancelOrder NOT throwing exception in case of success
            if ($exception instanceof RequestException) {
                $data = $this->json->unserialize($exception->getResponse()->getBody());

                $errorCode = $data['ErrorCode'] ?? null;

                if ($errorCode === 'ORDER_HAS_BEEN_CANCELLED') {
                    /** @var AdminTransactionResponseInterface $container */
                    $container = $this->payloadConverter->fromArray(
                        ['Status' => 'Refused'],
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
     * @inheirtDoc
     */
    public function updateMerchantReference(AdminUpdateMerchantReferenceRequestInterface $request, int|string|null $storeId = null): ?AdminTransactionResponseInterface
    {
        $container = null;
        $request->setRequestId($this->idGenerator->generateId());

        try {
            $payload = $this->payloadConverter->toArray($request);
            $response = $this->service->post('checkout/adminapi/v2/updatemerchantreference', $payload, $storeId);

            /** @var AdminTransactionResponseInterface $container */
            $container = $this->payloadConverter->fromArray($response, AdminTransactionResponseInterface::class);
        } catch (\Exception $exception) {
            $isExpectedConflict = false;
            $candidate = $exception;
            while ($candidate !== null) {
                if ($candidate instanceof RequestException) {
                    try {
                        $data = $this->json->unserialize($candidate->getResponse()->getBody());
                        $code = $data['ErrorCode'] ?? null;
                        if ($code === 'UPDATE_MERCHANT_REFERENCE_NOT_SUPPORTED'
                            || $code === 'NO_SUCCESSFUL_PAYMENT') {
                            $isExpectedConflict = true;
                        }
                    } catch (\Throwable) {
                    }
                    break;
                }
                $candidate = $candidate->getPrevious();
            }

            if ($isExpectedConflict) {
                $this->logManager->debug('updateMerchantReference rejected as expected: ' . $exception->getMessage(), [
                    'extra' => ['qliro_order_id' => $request->getOrderId() ?? 'unknown'],
                ]);
            } else {
                $this->logManager->critical($exception, [
                    'extra' => ['qliro_order_id' => $request->getOrderId() ?? 'unknown'],
                ]);
            }
        }

        return $container;
    }

    /**
     * @inheirtDoc
     */
    public function returnWithItems(AdminReturnWithItemsRequestInterface $request, int|string|null $storeId = null): AdminTransactionResponseInterface
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

            /** @var AdminTransactionResponseInterface $container */
            $container = $this->payloadConverter->fromArray( $paymentTransactions[0] ?? [], AdminTransactionResponseInterface::class);
        } catch (\Exception $exception) {
            $this->handleExceptions($exception);
        }

        return $container;
    }

    /**
     * @inheirtDoc
     */
    public function getPaymentTransaction(int $paymentTransactionId, int|string|null $storeId = null): AdminOrderPaymentTransactionInterface
    {
        $container = null;

        try {
            $response = $this->service->get(
                'checkout/adminapi/v2/paymentTransactions/{PaymentTransactionId}',
                ['PaymentTransactionId' => $paymentTransactionId],
                $storeId
            );

            /** @var AdminOrderPaymentTransactionInterface $container */
            $container = $this->payloadConverter->fromArray($response, AdminOrderPaymentTransactionInterface::class);
        } catch (\Exception $exception) {
            $this->handleExceptions($exception);
        }

        return $container;
    }

    /**
     * @inheirtDoc
     */
    public function retryReversalPayment(mixed $paymentReference, int|string|null $storeId = null): ?AdminOrderPaymentTransactionInterface
    {
        $container = null;

        try {
            $response = $this->service->post(
                'checkout/adminapi/v2/retryReversalPaymentTransaction',
                ['PaymentReference' => $paymentReference],
                $storeId
            );

            /** @var AdminOrderPaymentTransactionInterface $container */
            $container = $this->payloadConverter->fromArray($response, AdminOrderPaymentTransactionInterface::class);
        } catch (\Exception $exception) {
            $this->handleExceptions($exception);
        }

        return $container;
    }

    /**
     * Create a Merchant Payment
     *
     * @param AdminCreateMerchantPaymentRequestInterface $request
     * @param integer|null $storeId
     * @return AdminCreateMerchantPaymentResponseInterface|null
     * @throws ClientException
     */
    public function createMerchantPayment(
        AdminCreateMerchantPaymentRequestInterface $request,
        int|string|null $storeId = null
    ): ?AdminCreateMerchantPaymentResponseInterface {
        $container = null;

        try {
            $request->setRequestId($this->idGenerator->generateId());
            $payload = $this->payloadConverter->toArray($request);
            $response = $this->service->post(
                'checkout/adminapi/v2/merchantpayment',
                $payload,
                $storeId
            );

            /** @var AdminCreateMerchantPaymentResponseInterface $container */
            $container = $this->payloadConverter->fromArray(
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
