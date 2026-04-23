<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\Data\CheckoutStatusInterface as CheckoutStatusInterfaceAlias;
use Qliro\QliroOne\Api\Data\CheckoutStatusInterface;
use Qliro\QliroOne\Api\Data\CheckoutStatusResponseInterface;
use Qliro\QliroOne\Api\Data\CheckoutStatusResponseInterfaceFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\ResourceModel\Lock;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Exception\FailToLockException;

/**
 * QliroOne management class
 */
class CheckoutStatus extends AbstractManagement
{
    /**
     * @var \Qliro\QliroOne\Api\Client\MerchantInterface
     */
    private $merchantApi;

    /**
     * @var \Qliro\QliroOne\Api\LinkRepositoryInterface
     */
    private $linkRepository;

    /**
     * @var \Qliro\QliroOne\Model\Logger\Manager
     */
    private $logManager;

    /**
     * @var \Qliro\QliroOne\Model\ResourceModel\Lock
     */
    private $lock;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var \Qliro\QliroOne\Api\Data\CheckoutStatusResponseInterfaceFactory
     */
    private $checkoutStatusResponseFactory;

    /**
     * @var PlaceOrder
     */
    private $placeOrder;
    /**
     * @var QliroOrder
     */
    private $qliroOrder;

    /**
     * @var int|string|null
     */
    private $qliroOrderId = null;

    /**
     * @var bool
     */
    private $orderLocked = false;

    /**
     * Inject dependencies
     *
     * @param MerchantInterface $merchantApi
     * @param CheckoutStatusResponseInterfaceFactory $checkoutStatusResponseFactory
     * @param LinkRepositoryInterface $linkRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param LogManager $logManager
     * @param Lock $lock
     * @param PlaceOrder $placeOrder
     * @param QliroOrder $qliroOrder
     */
    public function __construct(
        MerchantInterface $merchantApi,
        CheckoutStatusResponseInterfaceFactory $checkoutStatusResponseFactory,
        LinkRepositoryInterface $linkRepository,
        OrderRepositoryInterface $orderRepository,
        LogManager $logManager,
        Lock $lock,
        PlaceOrder $placeOrder,
        QliroOrder $qliroOrder
    ) {
        $this->merchantApi = $merchantApi;
        $this->linkRepository = $linkRepository;
        $this->logManager = $logManager;
        $this->lock = $lock;
        $this->orderRepository = $orderRepository;
        $this->checkoutStatusResponseFactory = $checkoutStatusResponseFactory;
        $this->placeOrder = $placeOrder;
        $this->qliroOrder = $qliroOrder;
    }

    /**
     * @param CheckoutStatusInterfaceAlias $checkoutStatus
     * @return \Qliro\QliroOne\Api\Data\CheckoutStatusResponseInterface
     */
    public function update(CheckoutStatusInterface $checkoutStatus)
    {
        $qliroOrderId = $checkoutStatus->getOrderId();
        $callbackStatus = $checkoutStatus->getStatus();

        $logContext = [
            'extra' => [
                'qliro_order_id' => $qliroOrderId,
            ],
        ];

        try {
            try {
                $link = $this->linkRepository->getByQliroOrderId($qliroOrderId);
            } catch (\Exception $exception) {
                $this->handleOrderCancelationIfRequired($checkoutStatus);

                return $this->checkoutStatusRespond(
                    CheckoutStatusResponseInterface::RESPONSE_ORDER_NOT_FOUND,
                    500
                );
            }

            $this->logManager->setMerchantReference($link->getReference());

            $orderId = $link->getOrderId();

            if (empty($orderId)) {
                try {
                    $tooEarly = false;

                    if ($link->getCreatedAt()) {
                        $createdAt = strtotime($link->getCreatedAt());
                        $now = time();

                        if ($createdAt && ($now - $createdAt) < 2) {
                            $tooEarly = true;
                        }
                    }

                    if (!$tooEarly) {
                        if (!$this->lock->lock($qliroOrderId)) {
                            throw new FailToLockException(__('Failed to acquire lock when placing order'));
                        }

                        $this->orderLocked = true;

                        $link = $this->linkRepository->getByQliroOrderId($qliroOrderId);
                        $this->logManager->setMerchantReference($link->getReference());

                        $effectiveStatus = $this->resolveEffectiveCheckoutStatus($qliroOrderId, $callbackStatus);

                        $link->setQliroOrderStatus($effectiveStatus);
                        $this->linkRepository->save($link);

                        $orderId = $link->getOrderId();
                        if (!empty($orderId)) {
                            if ($this->placeOrder->applyQliroOrderStatus($this->orderRepository->get($orderId))) {
                                $response = $this->checkoutStatusRespond(
                                    CheckoutStatusResponseInterface::RESPONSE_RECEIVED
                                );
                            } else {
                                $response = $this->checkoutStatusRespond(
                                    CheckoutStatusResponseInterface::RESPONSE_ORDER_NOT_FOUND,
                                    500
                                );
                            }
                        } else {
                            $qliroOrder = $this->merchantApi->getOrder($qliroOrderId);
                            $this->placeOrder->execute($qliroOrder);

                            $response = $this->checkoutStatusRespond(
                                CheckoutStatusResponseInterface::RESPONSE_RECEIVED
                            );
                        }
                    } else {
                        $this->logManager->notice(
                            'checkoutStatus received too early, responding with order pending',
                            [
                                'extra' => [
                                    'qliro_order_id' => $qliroOrderId,
                                ],
                            ]
                        );

                        $response = $this->checkoutStatusRespond(
                            CheckoutStatusResponseInterface::RESPONSE_ORDER_PENDING
                        );
                    }
                } catch (FailToLockException $exception) {
                    throw $exception;
                } catch (\Exception $exception) {
                    $this->logManager->critical($exception, $logContext);

                    $response = $this->checkoutStatusRespond(
                        CheckoutStatusResponseInterface::RESPONSE_ORDER_NOT_FOUND,
                        500
                    );
                }
            } else {
                if (!$this->lock->lock($qliroOrderId)) {
                    throw new FailToLockException(__('Failed to acquire lock when updating order status'));
                }

                $this->orderLocked = true;

                $link = $this->linkRepository->getByQliroOrderId($qliroOrderId);
                $this->logManager->setMerchantReference($link->getReference());

                $effectiveStatus = $this->resolveEffectiveCheckoutStatus($qliroOrderId, $callbackStatus);

                $link->setQliroOrderStatus($effectiveStatus);
                $this->linkRepository->save($link);

                $orderId = $link->getOrderId();
                if ($this->placeOrder->applyQliroOrderStatus($this->orderRepository->get($orderId))) {
                    $response = $this->checkoutStatusRespond(
                        CheckoutStatusResponseInterface::RESPONSE_RECEIVED
                    );
                } else {
                    $response = $this->checkoutStatusRespond(
                        CheckoutStatusResponseInterface::RESPONSE_ORDER_NOT_FOUND,
                        500
                    );
                }
            }
        } catch (FailToLockException $exception) {
            $this->logManager->debug(
                sprintf(
                    'Lock failed for Qliro order id: %s Error: %s',
                    $qliroOrderId,
                    $exception->getMessage()
                )
            );

            $this->logManager->info(
                'Order is being created or updated in another process',
                [
                    'extra' => [
                        'qliro_order_id' => $qliroOrderId,
                    ],
                ]
            );

            $response = $this->checkoutStatusRespond(
                CheckoutStatusResponseInterface::RESPONSE_ORDER_PENDING
            );
        } catch (\Exception $exception) {
            $this->logManager->critical($exception, $logContext);

            $response = $this->checkoutStatusRespond(
                CheckoutStatusResponseInterface::RESPONSE_ORDER_NOT_FOUND,
                500
            );
        } finally {
            if ($this->orderLocked) {
                $this->lock->unlock($qliroOrderId);
                $this->orderLocked = false;
            }
        }

        return $response;
    }

    /**
     * Special case is processed here:
     * When the QliroOne order is not found, among active links, but push notification updates
     * status to "Completed", we want to find an inactive link and cancel such QliroOne order,
     * because Magento has previously failed creating corresponding order for it.
     *
     * @param CheckoutStatusInterfaceAlias $checkoutStatus
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     */
    private function handleOrderCancelationIfRequired(CheckoutStatusInterface $checkoutStatus)
    {
        $qliroOrderId = $checkoutStatus->getOrderId();

        if ($checkoutStatus->getStatus() === CheckoutStatusInterface::STATUS_COMPLETED) {
            $link = $this->linkRepository->getByQliroOrderId($qliroOrderId, false);

            if ($link->getQliroOrderStatus() === CheckoutStatusInterface::STATUS_COMPLETED) {
                $this->logManager->notice(
                    'Skipping QliroOne order cancellation because the local Qliro status is already completed',
                    [
                        'extra' => [
                            'qliro_order_id' => $qliroOrderId,
                            'link_id' => $link->getId(),
                        ],
                    ]
                );
                return;
            }

            try {
                $this->logManager->setMerchantReference($link->getReference());
                $link->setQliroOrderStatus($checkoutStatus->getStatus());
                $this->qliroOrder->cancel($link->getQliroOrderId());
                $link->setMessage(sprintf('Requested to cancel QliroOne order #%s', $link->getQliroOrderId()));
            } catch (TerminalException $exception) {
                $message = sprintf('Failed to cancel QliroOne order #%s', $link->getQliroOrderId());
                $this->logManager->critical(
                    $message,
                    ['exception' => $exception, 'extra' => $exception->getTrace()]
                );
                $link->setMessage($message);
            }

            $this->linkRepository->save($link);
        }
    }

    /**
     * Handles the checkout status response creation and performs necessary cleanup operations.
     *
     * @param mixed $result The result to be set in the callback response.
     * @param int $code The response code to be set in the callback response. Defaults to 200.
     * @return object The created response object with the result and response code set.
     */
    private function checkoutStatusRespond($result, $code = 200)
    {
        $response = $this->checkoutStatusResponseFactory->create();
        $response->setCallbackResponse($result);
        $response->setCallbackResponseCode($code);

        return $response;
    }

    /**
     * Resolves the effective checkout status by fetching the status from the Qliro order API
     * or falling back to a provided status if the API call fails or returns an empty status.
     *
     * @param string $qliroOrderId The identifier of the Qliro order to fetch the checkout status for.
     * @param string $fallbackStatus The fallback status to be used if the API call fails or no status is returned.
     * @return string The resolved effective checkout status, either fetched from the API or the fallback status.
     */
    private function resolveEffectiveCheckoutStatus($qliroOrderId, $fallbackStatus): string
    {
        try {
            $qliroOrder = $this->merchantApi->getOrder($qliroOrderId);
            $apiStatus = $qliroOrder ? (string)$qliroOrder->getCustomerCheckoutStatus() : '';

            $this->logManager->info(
                'Resolved effective checkout status',
                [
                    'extra' => [
                        'qliro_order_id' => $qliroOrderId,
                        'callback_status' => $fallbackStatus,
                        'api_status' => $apiStatus,
                    ],
                ]
            );

            if ($apiStatus !== '') {
                return $apiStatus;
            }
        } catch (\Exception $exception) {
            $this->logManager->warning(
                'Failed to fetch current Qliro checkout status, using callback status fallback',
                [
                    'extra' => [
                        'qliro_order_id' => $qliroOrderId,
                        'callback_status' => $fallbackStatus,
                        'error' => $exception->getMessage(),
                    ],
                ]
            );
        }

        return (string)$fallbackStatus;
    }
}
