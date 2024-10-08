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
        $logContext = [
            'extra' => [
                'qliro_order_id' => $qliroOrderId,
            ],
        ];

        try {
            if (!$this->lock->lock($qliroOrderId)) {
                throw new FailToLockException(__('Failed to aquire lock when placing order'));
            }

            try {
                $link = $this->linkRepository->getByQliroOrderId($qliroOrderId);
            } catch (NoSuchEntityException $exception) {
                $this->handleOrderCancelationIfRequired($checkoutStatus);
                throw $exception;
            }

            $this->logManager->setMerchantReference($link->getReference());

            $link->setQliroOrderStatus($checkoutStatus->getStatus());
            $this->linkRepository->save($link);

            $orderId = $link->getOrderId();

            if (empty($orderId)) {
                /*
                 * First major scenario:
                 * There is not yet any Magento order. Attempt to create the order, placeOrder()
                 * will process the created order based on the QliroOne order status as found in the link.
                 */

                try {
                    // TODO: the quote is still active, so the shopper might be adding more items
                    // TODO: without knowing that there is no order yet

                    $curTimeStamp = time();
                    $tooEarly = false;
                    $placedTimeStamp = strtotime($link->getPlacedAt()??'');
                    $updTimeStamp = strtotime($link->getUpdatedAt()??'');
                    if ($placedTimeStamp && $curTimeStamp < $placedTimeStamp + self::QLIRO_POLL_VS_CHECKOUT_STATUS_TIMEOUT) {
                        $tooEarly = true;
                    }
                    if ($curTimeStamp < $updTimeStamp + self::QLIRO_POLL_VS_CHECKOUT_STATUS_TIMEOUT_FINAL) {
                        $tooEarly = true;
                    }

                    if (!$tooEarly) {
                        $responseContainer = $this->merchantApi->getOrder($qliroOrderId);
                        $this->placeOrder->execute($responseContainer);

                        $response = $this->checkoutStatusRespond(CheckoutStatusResponseInterface::RESPONSE_RECEIVED);
                    } else {
                        $this->logManager->notice(
                            'checkoutStatus received too early, responding with order pending',
                            $logContext
                        );

                        $response = $this->checkoutStatusRespond(CheckoutStatusResponseInterface::RESPONSE_ORDER_PENDING);
                    }
                } catch (\Exception $exception) {
                    $this->logManager->critical($exception, $logContext);

                    $response = $this->checkoutStatusRespond(CheckoutStatusResponseInterface::RESPONSE_ORDER_NOT_FOUND, 500);
                }
            } elseif (in_array(
                $checkoutStatus->getStatus(),
                [CheckoutStatusInterfaceAlias::STATUS_ONHOLD, CheckoutStatusInterfaceAlias::STATUS_REFUSED]
            )) {
                /*
                 * Second major scenario:
                 * The order already exists; if the status is OnHold or Refused, Order status should be updated
                 */
                if ($this->placeOrder->applyQliroOrderStatus($this->orderRepository->get($orderId))) {
                    $response = $this->checkoutStatusRespond(CheckoutStatusResponseInterface::RESPONSE_RECEIVED);
                } else {
                    $response = $this->checkoutStatusRespond(CheckoutStatusResponseInterface::RESPONSE_ORDER_NOT_FOUND, 500);
                }
            } elseif ($checkoutStatus->getStatus() === CheckoutStatusInterfaceAlias::STATUS_COMPLETED) {
                /**
                 * Third major scenario: Order exists and is completed
                 *   = everyhing's good and nothing else to do!
                 */
                $response = $this->checkoutStatusRespond(CheckoutStatusResponseInterface::RESPONSE_RECEIVED);
            }
            $this->lock->unlock($qliroOrderId);

        } catch (NoSuchEntityException $exception) {
            /* no more qliro pushes should be sent */
            $response = $this->checkoutStatusRespond(CheckoutStatusResponseInterface::RESPONSE_RECEIVED);

        } catch (FailToLockException $exception) {
            /*
             * Someone else is creating the order at the moment. Let Qliro try again in a few minutes.
             */
            $this->logManager->info('Order is being created in another process', $logContext);
            $response = $this->checkoutStatusRespond(CheckoutStatusResponseInterface::RESPONSE_ORDER_PENDING);

        } catch (\Exception $exception) {
            $this->logManager->critical($exception, $logContext);
            $response = $this->checkoutStatusRespond(CheckoutStatusResponseInterface::RESPONSE_ORDER_NOT_FOUND, 500);

        }

        // Unknown scenario, no response created. Should not happen, respond with Order Not Found
        if (!isset($response)) {
            $response = $this->checkoutStatusRespond(CheckoutStatusResponseInterface::RESPONSE_ORDER_NOT_FOUND, 500);
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

            try {
                $this->logManager->setMerchantReference($link->getReference());
                $link->setQliroOrderStatus($checkoutStatus->getStatus());
                $this->qliroOrder->cancel($link->getQliroOrderId());
                $link->setMessage(sprintf('Requested to cancel QliroOne order #%s', $link->getQliroOrderId()));
            } catch (TerminalException $exception) {
                $link->setMessage(sprintf('Failed to cancel QliroOne order #%s', $link->getQliroOrderId()));
            }

            $this->linkRepository->save($link);
        }
    }

    /**
     * @param string $result
     * @param int $code
     * @return mixed
     */
    private function checkoutStatusRespond($result, $code = 200)
    {
        $response = $this->checkoutStatusResponseFactory->create();
        $response->setCallbackResponse($result);
        $response->setCallbackResponseCode($code);
        return $response;
    }
}
