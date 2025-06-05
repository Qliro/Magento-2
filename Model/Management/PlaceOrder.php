<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\AdminUpdateMerchantReferenceRequestInterface;
use Qliro\QliroOne\Api\Data\QliroOrderInterface;
use Qliro\QliroOne\Api\Data\CheckoutStatusInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Exception\OrderPlacementPendingException;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Order\OrderPlacer;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromOrderConverter;
use Qliro\QliroOne\Model\ResourceModel\Lock;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Exception\FailToLockException;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Service\RecurringPayments\Data as RecurringDataService;

/**
 * QliroOne management class
 */
class PlaceOrder extends AbstractManagement
{
    /**
     * @var \Qliro\QliroOne\Model\Config
     */
    private $qliroConfig;

    /**
     * @var \Qliro\QliroOne\Api\Client\MerchantInterface
     */
    private $merchantApi;

    /**
     * @var \Qliro\QliroOne\Api\Client\OrderManagementInterface
     */
    private $orderManagementApi;

    /**
     * @var \Qliro\QliroOne\Api\LinkRepositoryInterface
     */
    private $linkRepository;

    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    private $quoteRepository;

    /**
     * @var \Qliro\QliroOne\Model\ContainerMapper
     */
    private $containerMapper;

    /**
     * @var \Qliro\QliroOne\Model\Logger\Manager
     */
    private $logManager;

    /**
     * @var \Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromOrderConverter
     */
    private $quoteFromOrderConverter;

    /**
     * @var \Qliro\QliroOne\Model\Order\OrderPlacer
     */
    private $orderPlacer;

    /**
     * @var \Qliro\QliroOne\Model\ResourceModel\Lock
     */
    private $lock;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var \Magento\Sales\Model\Order\Email\Sender\OrderSender
     */
    private $orderSender;
    /**
     * @var Quote
     */
    private $quoteManagement;
    /**
     * @var Payment
     */
    private $paymentManagement;

    /**
     * @var \Qliro\QliroOne\Service\RecurringPayments\Data
     */
    private $recurringDataService;

    /**
     * Inject dependencies
     *
     * @param Config $qliroConfig
     * @param MerchantInterface $merchantApi
     * @param OrderManagementInterface $orderManagementApi
     * @param QuoteFromOrderConverter $quoteFromOrderConverter
     * @param LinkRepositoryInterface $linkRepository
     * @param CartRepositoryInterface $quoteRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param ContainerMapper $containerMapper
     * @param LogManager $logManager
     * @param OrderPlacer $orderPlacer
     * @param Lock $lock
     * @param OrderSender $orderSender
     * @param Quote $quoteManagement
     * @param Payment $paymentManagement
     * @param RecurringDataService $recurringDataService
     */
    public function __construct(
        Config $qliroConfig,
        MerchantInterface $merchantApi,
        OrderManagementInterface $orderManagementApi,
        QuoteFromOrderConverter $quoteFromOrderConverter,
        LinkRepositoryInterface $linkRepository,
        CartRepositoryInterface $quoteRepository,
        OrderRepositoryInterface $orderRepository,
        ContainerMapper $containerMapper,
        LogManager $logManager,
        OrderPlacer $orderPlacer,
        Lock $lock,
        OrderSender $orderSender,
        Quote $quoteManagement,
        Payment $paymentManagement,
        RecurringDataService $recurringDataService
    ) {
        $this->qliroConfig = $qliroConfig;
        $this->merchantApi = $merchantApi;
        $this->orderManagementApi = $orderManagementApi;
        $this->linkRepository = $linkRepository;
        $this->quoteRepository = $quoteRepository;
        $this->containerMapper = $containerMapper;
        $this->logManager = $logManager;
        $this->quoteFromOrderConverter = $quoteFromOrderConverter;
        $this->orderPlacer = $orderPlacer;
        $this->lock = $lock;
        $this->orderRepository = $orderRepository;
        $this->orderSender = $orderSender;
        $this->quoteManagement = $quoteManagement;
        $this->paymentManagement = $paymentManagement;
        $this->recurringDataService = $recurringDataService;
    }

    /**
     * Poll for Magento order placement and return order increment ID if successful
     *
     * @return \Magento\Sales\Model\Order
     * @throws TerminalException
     */
    public function poll()
    {
        $quoteId = $this->getQuote()->getId();

        try {
            $link = $this->linkRepository->getByQuoteId($quoteId);
            $orderId = $link->getOrderId();
            $qliroOrderId = $link->getQliroOrderId();
            $this->logManager->setMerchantReference($link->getReference());

            if (empty($orderId)) {
                try {
                    $responseContainer = $this->merchantApi->getOrder($qliroOrderId);

                    if ($responseContainer->getCustomerCheckoutStatus() == CheckoutStatusInterface::STATUS_IN_PROCESS) {
                        throw new OrderPlacementPendingException(
                            __('QliroOne order status is "InProcess" and order cannot be placed.')
                        );
                    }
                    if (!$this->lock->lock($qliroOrderId)) {
                        throw new FailToLockException(__('Failed to aquire lock when placing order'));
                    }

                    $this->prepareQuoteRecurringInfo();
                    $order = $this->execute($responseContainer);
                    $this->handlePlacedOrderRecurringInfo($order, $responseContainer->getCustomer()->getPersonalNumber());

                    $this->lock->unlock($qliroOrderId);

                } catch (FailToLockException $exception) {
                    $this->logManager->critical(
                        $exception,
                        [
                            'extra' => [
                                'quote_id' => $quoteId,
                                'qliro_order_id' => $qliroOrderId,
                            ],
                        ]
                    );

                    throw $exception;
                } catch (OrderPlacementPendingException $exception) {
                    $this->logManager->critical(
                        $exception,
                        [
                            'extra' => [
                                'quote_id' => $quoteId,
                                'qliro_order_id' => $qliroOrderId,
                            ],
                        ]
                    );
                    $this->lock->unlock($qliroOrderId);

                    throw $exception;
                } catch (\Exception $exception) {
                    $this->logManager->critical(
                        $exception,
                        [
                            'extra' => [
                                'quote_id' => $quoteId,
                                'qliro_order_id' => $qliroOrderId,
                            ],
                        ]
                    );
                    $this->lock->unlock($qliroOrderId);

                    throw new TerminalException('Order placement failed', $exception->getCode(), $exception);
                }
            } else {
                $order = $this->orderRepository->get($orderId);
            }
        } catch (NoSuchEntityException $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'quote_id' => $quoteId,
                        'order_id' => $orderId ?? null,
                        'qliro_order_id' => $qliroOrderId ?? null,
                    ],
                ]
            );
            throw new TerminalException('Failed to link current session with Qliro One order', $exception->getCode(), $exception);
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'quote_id' => $quoteId,
                        'order_id' => $orderId ?? null,
                        'qliro_order_id' => $qliroOrderId ?? null,
                    ],
                ]
            );

            throw new TerminalException('Something went wrong during order placement polling', $exception->getCode(), $exception);
        }

        return $order;
    }

    /**
     * Get a QliroOne order, update the quote, then place Magento order
     * If placeOrder is successful, it returns the Magento Order
     * If an error occurs it returns null
     * If it's not possible to aquire lock, it returns false
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderInterface $qliroOrder
     * @param string $state
     * @return \Magento\Sales\Model\Order
     * @throws TerminalException
     * @todo May require doing something upon $this->applyQliroOrderStatus($orderId) returning false
     */
    public function execute(QliroOrderInterface $qliroOrder, $state = Order::STATE_PENDING_PAYMENT)
    {
        $qliroOrderId = $qliroOrder->getOrderId();

        $this->logManager->setMark('PLACE ORDER');
        $order = null; // Placeholder, this method may never return null as an order

        try {
            $link = $this->linkRepository->getByQliroOrderId($qliroOrderId);

            try {
                if ($orderId = $link->getOrderId()) {
                    $this->logManager->debug(
                        'Order is already created, skipping',
                        [
                            'extra' => [
                                'qliro_order' => $qliroOrderId,
                                'quote_id' => $this->getQuote()->getId(),
                                'order_id' => $orderId,
                            ],
                        ]
                    );

                    $order = $this->orderRepository->get($orderId);
                } else {
                    $this->setQuote($this->quoteRepository->get($link->getQuoteId()));

                    $this->logManager->debug(
                        'Placing order',
                        [
                            'extra' => [
                                'qliro_order' => $qliroOrderId,
                                'quote_id' => $this->getQuote()->getId(),
                            ],
                        ]
                    );

                    $this->quoteFromOrderConverter->convert($qliroOrder, $this->getQuote());
                    $this->addAdditionalInfoToQuote($link, $qliroOrder->getPaymentMethod());
                    $this->addAdditionalShippingInfoToQuote($qliroOrder);
                    $this->quoteManagement->setQuote($this->getQuote())->recalculateAndSaveQuote();

                    $order = $this->orderPlacer->place($this->getQuote());
                    $orderId = $order->getId();

                    $link->setOrderId($orderId);
                    $this->linkRepository->save($link);

                    $this->paymentManagement->createPaymentTransaction($order, $qliroOrder, $state);

                    $this->logManager->debug(
                        'Order placed successfully',
                        [
                            'extra' => [
                                'qliro_order' => $qliroOrderId,
                                'quote_id' => $this->getQuote()->getId(),
                                'order_id' => $orderId,
                            ],
                        ]
                    );

                    $link->setMessage(sprintf('Created order %s', $order->getIncrementId()));
                    $this->linkRepository->save($link);
                }

                $this->applyQliroOrderStatus($order);
            } catch (\Exception $exception) {
                $link->setIsActive(false);
                $link->setMessage($exception->getMessage());
                $this->linkRepository->save($link);

                $this->logManager->critical(
                    $exception,
                    [
                        'extra' => [
                            'qliro_order_id' => $qliroOrderId,
                            'quote_id' => $link->getQuoteId(),
                        ],
                    ]
                );

                throw $exception;
            }
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'qliro_order_id' => $qliroOrderId,
                    ],
                ]
            );

            throw new TerminalException($exception->getMessage(), $exception->getCode(), $exception);
        } finally {
            $this->logManager->setMark(null);
        }

        return $order;
    }

    /**
     * Act on the order based on the qliro order status
     * It can be one of:
     * - Completed - the order can be shipped
     * - OnHold - review of buyer require more time
     * - Refused - deny the purchase
     *
     * @param Order $order
     * @return bool
     */
    public function applyQliroOrderStatus($order)
    {
        $orderId = $order->getId();

        try {
            $link = $this->linkRepository->getByOrderId($orderId);

            switch ($link->getQliroOrderStatus()) {
                case CheckoutStatusInterface::STATUS_COMPLETED:
                    if ($order->getCanSendNewEmailFlag() && !$order->getEmailSent()) {
                        try {
                            $this->orderSender->send($order);
                        } catch (\Exception $exception) {
                            $this->logManager->critical(
                                $exception,
                                [
                                    'extra' => [
                                        'order_id' => $orderId,
                                    ],
                                ]
                            );
                        }
                    }

                    $paymentAdditionalInfo = $order->getPayment()->getAdditionalInformation();
                    $alreadyUpdatedMerchantRef = $paymentAdditionalInfo['qliroone_updated_merchant_reference'] ?? false;

                    if (!$alreadyUpdatedMerchantRef) {
                        /*
                        * If Magento order has already been placed and QliroOne order status is completed,
                        * the order merchant reference must be replaced with Magento order increment ID
                        */
                        /** @var \Qliro\QliroOne\Api\Data\AdminUpdateMerchantReferenceRequestInterface $request */
                        $request = $this->containerMapper->fromArray(
                            [
                                'OrderId' => $link->getQliroOrderId(),
                                'NewMerchantReference' => $order->getIncrementId(),
                            ],
                            AdminUpdateMerchantReferenceRequestInterface::class
                        );

                        $response = $this->orderManagementApi->updateMerchantReference($request, $order->getStoreId());
                        $transactionId = 'unknown';
                        if ($response && $response->getPaymentTransactionId()) {
                            $transactionId = $response->getPaymentTransactionId();
                        }
                        $this->logManager->debug('New merchant reference was assigned to the Qliro One order', [
                            'payment_transaction_id' => $transactionId,
                            'qliro_order_id' => $link->getQliroOrderId(),
                            'order_id' => $order->getId(),
                            'new_merchant_reference' => $order->getIncrementId(),
                        ]);

                        $paymentAdditionalInfo['qliroone_updated_merchant_reference'] = true;
                        $order->getPayment()->setAdditionalInformation($paymentAdditionalInfo);
                    }

                    // Finally apply state, this also saves the order and payment
                    $this->applyOrderState($order, Order::STATE_NEW);
                    break;

                case CheckoutStatusInterface::STATUS_ONHOLD:
                    $this->applyOrderState($order, Order::STATE_PAYMENT_REVIEW);
                    break;

                case CheckoutStatusInterface::STATUS_REFUSED:
                    $link->setIsActive(false);
                    $link->setMessage(sprintf('Order #%s marked as canceled', $order->getIncrementId()));
                    $this->linkRepository->save($link);

                    $order->addCommentToStatusHistory(
                        'Customer checkout was refused by QLiro. Proceeding with order cancellation.'
                    )->setIsCustomerNotified(false);

                    $order->getPayment()->setNotificationResult(true);
                    $order->getPayment()->deny(false);

                    $this->orderRepository->save($order);

                    break;

                case CheckoutStatusInterface::STATUS_IN_PROCESS:
                default:
                    $this->logManager->debug(
                        'Order status is not completed, on hold or refused',
                        [
                            'extra' => [
                                'order_id' => $orderId,
                                'qliro_order_id' => $link->getQliroOrderId(),
                                'qliro_order_status' => $link->getQliroOrderStatus(),
                            ],
                        ]
                    );
                    return false;
            }

            return true;
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'order_id' => $orderId,
                    ],
                ]
            );

            return false;
        }
    }

    /**
     * Add information regarding this purchase to Quote, which will transfer to Order
     *
     * @param \Qliro\QliroOne\Api\Data\LinkInterface $link
     * @param \Qliro\QliroOne\Api\Data\QliroOrderPaymentMethodInterface $paymentMethod
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function addAdditionalInfoToQuote($link, $paymentMethod)
    {
        $payment = $this->getQuote()->getPayment();
        $payment->setAdditionalInformation(Config::QLIROONE_ADDITIONAL_INFO_QLIRO_ORDER_ID, $link->getQliroOrderId());
        $payment->setAdditionalInformation(Config::QLIROONE_ADDITIONAL_INFO_REFERENCE, $link->getReference());

        if ($paymentMethod) {
            $payment->setAdditionalInformation(
                Config::QLIROONE_ADDITIONAL_INFO_PAYMENT_METHOD_CODE,
                $paymentMethod->getPaymentTypeCode()
            );

            $payment->setAdditionalInformation(
                Config::QLIROONE_ADDITIONAL_INFO_PAYMENT_METHOD_NAME,
                $paymentMethod->getPaymentMethodName()
            );
        }
    }

    /**
     * @param QliroOrderInterface $order
     * @return void
     */
    private function addAdditionalShippingInfoToQuote(QliroOrderInterface $order)
    {
        $payment = $this->getQuote()->getPayment();
        foreach ($order->getOrderItems() as $orderItem) {
            $metadata = $orderItem->getMetadata();
            $additionalShippingProperties = $metadata['AdditionalShippingProperties'] ?? false;
            if (!$additionalShippingProperties) {
                continue;
            }

            $payment->setAdditionalInformation(
                Config::QLIROONE_ADDITIONAL_INFO_SHIPPING_PROPERTIES,
                $additionalShippingProperties
            );
            return;
        }
    }

    /**
     * Apply a proper state with its default status to the order
     *
     * @param \Magento\Sales\Model\Order $order
     * @param string $state
     */
    private function applyOrderState(Order $order, $state)
    {
        $status = Order::STATE_NEW === $state
            ? $this->qliroConfig->getOrderStatus()
            : $order->getConfig()->getStateDefaultStatus($state);

        $order->setState($state);
        $order->setStatus($status);
        $this->orderRepository->save($order);
    }

    /**
     * If recurring is enabled and selected in the quote, sets next recurring order date
     *
     * @return void
     */
    private function prepareQuoteRecurringInfo(): void
    {
        if (!$this->qliroConfig->isUseRecurring()) {
            return;
        }

        $recurringInfo = $this->recurringDataService->quoteGetter($this->getQuote());
        if (!$recurringInfo->getEnabled()) {
            return;
        }

        $this->recurringDataService->scheduleNextRecurringOrder($this->getQuote());
    }

    /**
     * If recurring is enabled and selected for order, will save a Recurring Info entity for the order
     *
     * @param Order $order
     * @param string $personalNumber
     * @return void
     */
    private function handlePlacedOrderRecurringInfo(Order $order, $personalNumber): void
    {
        if (!$this->qliroConfig->isUseRecurring()) {
            return;
        }

        $recurringInfo = $this->recurringDataService->orderGetter($order);
        if (!$recurringInfo->getEnabled()) {
            return;
        }

        $this->recurringDataService->saveNewOrderRecurringInfo($order, $personalNumber);
    }
}
