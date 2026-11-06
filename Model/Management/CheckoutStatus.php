<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\Data\CheckoutStatusInterface as CheckoutStatusInterfaceAlias;
use Qliro\QliroOne\Api\Data\CheckoutStatusInterface;
use Qliro\QliroOne\Api\Data\CheckoutStatusResponseInterface;
use Qliro\QliroOne\Api\Data\CheckoutStatusResponseInterfaceFactory;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Config;
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
            } catch (NoSuchEntityException $exception) {
                return $this->linkNotFound($checkoutStatus);
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
                            throw new FailToLockException((string)__('Failed to acquire lock when placing order'));
                        }

                        $this->orderLocked = true;

                        try {
                            $link = $this->linkRepository->getByQliroOrderId($qliroOrderId);
                        } catch (NoSuchEntityException $exception) {
                            return $this->linkNotFound($checkoutStatus);
                        }
                        $this->logManager->setMerchantReference($link->getReference());

                        $effectiveStatus = $this->resolveEffectiveCheckoutStatus($qliroOrderId, $callbackStatus);

                        $link->setQliroOrderStatus($effectiveStatus);
                        $this->linkRepository->save($link);

                        if (!empty($link->getOrderId())) {
                            $response = $this->applyStatus($link);
                        } else {
                            // A status push from Qliro, with nobody in front of it
                            $response = $this->place($qliroOrderId);
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

                    $response = $this->refuse(
                        CheckoutStatusResponseInterface::RESPONSE_STATUS_NOT_APPLIED,
                        $this->detailOf($exception)
                    );
                }
            } else {
                if (!$this->lock->lock($qliroOrderId)) {
                    throw new FailToLockException((string)__('Failed to acquire lock when updating order status'));
                }

                $this->orderLocked = true;

                try {
                    $link = $this->linkRepository->getByQliroOrderId($qliroOrderId);
                } catch (NoSuchEntityException $exception) {
                    return $this->linkNotFound($checkoutStatus);
                }
                $this->logManager->setMerchantReference($link->getReference());

                $effectiveStatus = $this->resolveEffectiveCheckoutStatus($qliroOrderId, $callbackStatus);

                $link->setQliroOrderStatus($effectiveStatus);
                $this->linkRepository->save($link);

                $response = $this->applyStatus($link);
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

            $response = $this->refuse(
                CheckoutStatusResponseInterface::RESPONSE_STATUS_NOT_APPLIED,
                $this->detailOf($exception)
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
     * No active link: cancel the Qliro order if needed, a retry cannot find one either
     *
     * @param CheckoutStatusInterface $checkoutStatus
     * @return CheckoutStatusResponseInterface
     */
    private function linkNotFound(CheckoutStatusInterface $checkoutStatus)
    {
        $logContext = [
            'extra' => [
                'qliro_order_id' => $checkoutStatus->getOrderId(),
                'status' => $checkoutStatus->getStatus(),
            ],
        ];

        try {
            $this->handleOrderCancelationIfRequired($checkoutStatus);
        } catch (NoSuchEntityException $exception) {
            // No link at all, nothing to cancel
        } catch (\Exception $exception) {
            $this->logManager->critical($exception, $logContext);
        }

        $this->logManager->warning('Checkout status push refused, no active link for the Qliro order', $logContext);

        return $this->refuse(CheckoutStatusResponseInterface::RESPONSE_LINK_NOT_FOUND, null, 404);
    }

    /**
     * Place the order for a push nobody is waiting for
     *
     * @param int|string $qliroOrderId
     * @return CheckoutStatusResponseInterface
     */
    private function place($qliroOrderId)
    {
        try {
            $qliroOrder = $this->merchantApi->getOrder($qliroOrderId, Config::API_PROFILE_BACKGROUND);
            $this->placeOrder->execute($qliroOrder);
        } catch (\Exception $exception) {
            $this->logManager->critical(
                'Order placement failed: ' . $exception->getMessage(),
                [
                    'extra' => [
                        'qliro_order_id' => $qliroOrderId,
                        'error' => $exception->getMessage(),
                    ],
                ]
            );

            return $this->refuse(
                CheckoutStatusResponseInterface::RESPONSE_PLACEMENT_FAILED,
                $this->detailOf($exception)
            );
        }

        return $this->checkoutStatusRespond(CheckoutStatusResponseInterface::RESPONSE_RECEIVED);
    }

    /**
     * Apply the link's Qliro status to its order
     *
     * @param LinkInterface $link
     * @return CheckoutStatusResponseInterface
     * @throws NoSuchEntityException
     */
    private function applyStatus(LinkInterface $link)
    {
        if ($this->placeOrder->applyQliroOrderStatus($this->orderRepository->get($link->getOrderId()), $link)) {
            return $this->checkoutStatusRespond(CheckoutStatusResponseInterface::RESPONSE_RECEIVED);
        }

        $status = (string)$link->getQliroOrderStatus();
        $this->logManager->warning(
            'Checkout status push refused, the status could not be applied to the order',
            [
                'extra' => [
                    'qliro_order_id' => $link->getQliroOrderId(),
                    'order_id' => $link->getOrderId(),
                    'status' => $status,
                ],
            ]
        );

        $handled = [
            CheckoutStatusInterface::STATUS_COMPLETED,
            CheckoutStatusInterface::STATUS_ONHOLD,
            CheckoutStatusInterface::STATUS_REFUSED,
        ];

        return $this->refuse(
            CheckoutStatusResponseInterface::RESPONSE_STATUS_NOT_APPLIED,
            $status === '' || in_array($status, $handled, true) ? null : sprintf('status %s', $status)
        );
    }

    /**
     * The detail of a refusal: Qliro logs the answer, so a database error is named but not quoted,
     * its text can hold the values of a row. The store's log keeps the whole message
     *
     * @param \Throwable $exception
     * @return string
     */
    private function detailOf(\Throwable $exception): string
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof \PDOException
                || $cause instanceof \Zend_Db_Exception
                || preg_match('/SQLSTATE|Integrity constraint|Duplicate entry/i', $cause->getMessage())
            ) {
                return 'database error, see the store log';
            }
        }

        return $exception->getMessage();
    }

    /**
     * A refusal names its cause, with a short single-line detail
     *
     * @param string $cause
     * @param string|null $detail
     * @param int $code
     * @return CheckoutStatusResponseInterface
     */
    private function refuse(string $cause, ?string $detail = null, int $code = 500)
    {
        $detail = trim((string)preg_replace('/\s+/', ' ', mb_scrub((string)$detail, 'UTF-8')));

        if ($detail !== '') {
            $cause .= ': ' . mb_substr($detail, 0, 200);
        }

        return $this->checkoutStatusRespond($cause, $code);
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
            $qliroOrder = $this->merchantApi->getOrder($qliroOrderId, Config::API_PROFILE_BACKGROUND);
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
