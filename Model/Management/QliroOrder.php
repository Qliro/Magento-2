<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderNotificationInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderResponseInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Exception\AlreadyPlacedException;
use Qliro\QliroOne\Model\Exception\LinkInactiveException;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\Admin\CancelOrderRequest;
use Qliro\QliroOne\Model\QliroOrder\Builder\UpdateRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\ValidateOrderBuilder;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromOrderConverter;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromValidateConverter;
use Qliro\QliroOne\Model\ResourceModel\Lock;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterfaceFactory;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterface;

/**
 * QliroOne management class
 */
class QliroOrder extends AbstractManagement
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
     * @var \Qliro\QliroOne\Model\QliroOrder\Builder\ValidateOrderBuilder
     */
    private $validateOrderBuilder;

    /**
     * @var \Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromValidateConverter
     */
    private $quoteFromValidateConverter;

    /**
     * @var \Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromOrderConverter
     */
    private $quoteFromOrderConverter;

    /**
     * @var \Qliro\QliroOne\Model\ResourceModel\Lock
     */
    private $lock;

    /**
     * @var \Qliro\QliroOne\Model\QliroOrder\Builder\UpdateRequestBuilder
     */
    private $updateRequestBuilder;

    /**
     * @var \Magento\Sales\Api\OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * @var \Qliro\QliroOne\Api\Data\OrderManagementStatusInterfaceFactory
     */
    private $orderManagementStatusInterfaceFactory;

    /**
     * @var OrderManagementStatusRepositoryInterface
     */
    private $orderManagementStatusRepository;
    /**
     * @var Quote
     */
    private $quoteManagement;

    /**
     * Inject dependencies
     * @param Config $qliroConfig
     * @param MerchantInterface $merchantApi
     * @param OrderManagementInterface $orderManagementApi
     * @param UpdateRequestBuilder $updateRequestBuilder
     * @param ValidateOrderBuilder $validateOrderBuilder
     * @param QuoteFromValidateConverter $quoteFromValidateConverter
     * @param QuoteFromOrderConverter $quoteFromOrderConverter
     * @param LinkRepositoryInterface $linkRepository
     * @param CartRepositoryInterface $quoteRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param ContainerMapper $containerMapper
     * @param LogManager $logManager
     * @param Lock $lock
     * @param OrderManagementStatusInterfaceFactory $orderManagementStatusInterfaceFactory
     * @param OrderManagementStatusRepositoryInterface $orderManagementStatusRepository
     */
    public function __construct(
        Config $qliroConfig,
        MerchantInterface $merchantApi,
        OrderManagementInterface $orderManagementApi,
        UpdateRequestBuilder $updateRequestBuilder,
        ValidateOrderBuilder $validateOrderBuilder,
        QuoteFromValidateConverter $quoteFromValidateConverter,
        QuoteFromOrderConverter $quoteFromOrderConverter,
        LinkRepositoryInterface $linkRepository,
        CartRepositoryInterface $quoteRepository,
        OrderRepositoryInterface $orderRepository,
        ContainerMapper $containerMapper,
        LogManager $logManager,
        Lock $lock,
        OrderManagementStatusInterfaceFactory $orderManagementStatusInterfaceFactory,
        OrderManagementStatusRepositoryInterface $orderManagementStatusRepository,
        Quote $quoteManagement
    ) {
        $this->qliroConfig = $qliroConfig;
        $this->merchantApi = $merchantApi;
        $this->orderManagementApi = $orderManagementApi;
        $this->linkRepository = $linkRepository;
        $this->quoteRepository = $quoteRepository;
        $this->containerMapper = $containerMapper;
        $this->logManager = $logManager;
        $this->validateOrderBuilder = $validateOrderBuilder;
        $this->quoteFromValidateConverter = $quoteFromValidateConverter;
        $this->quoteFromOrderConverter = $quoteFromOrderConverter;
        $this->lock = $lock;
        $this->updateRequestBuilder = $updateRequestBuilder;
        $this->orderRepository = $orderRepository;
        $this->orderManagementStatusInterfaceFactory = $orderManagementStatusInterfaceFactory;
        $this->orderManagementStatusRepository = $orderManagementStatusRepository;
        $this->quoteManagement = $quoteManagement;
    }

    /**
     * Fetch a QliroOne order and return it as a container
     *
     * @return \Qliro\QliroOne\Api\Data\QliroOrderInterface
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     */
    public function get()
    {
        $link = $this->quoteManagement->setQuote($this->getQuote())->getLinkFromQuote();
        $this->logManager->setMark('GET QLIRO ORDER');

        $qliroOrder = null; // Logical placeholder, may never happen

        try {
            $qliroOrderId = $link->getQliroOrderId();
            $qliroOrder = $this->merchantApi->getOrder($qliroOrderId);

            if ($this->lock->lock($qliroOrderId)) {
                if (empty($link->getOrderId())) {
                    if ($qliroOrder->isPlaced()) {
                        $this->lock->unlock($qliroOrderId);
                        throw new AlreadyPlacedException('Order has already been placed.');
                    }
                    try {
                        $this->quoteFromOrderConverter->convert($qliroOrder, $this->getQuote());
                        $this->quoteManagement->recalculateAndSaveQuote();
                    } catch (\Exception $exception) {
                        $this->logManager->debug(
                            $exception,
                            [
                                'extra' => [
                                    'link_id' => $link->getId(),
                                    'quote_id' => $link->getQuoteId(),
                                    'qliro_order_id' => $qliroOrderId,
                                ],
                            ]
                        );

                        $this->lock->unlock($qliroOrderId);
                        throw $exception;
                    }
                }

                $this->lock->unlock($qliroOrderId);
            } else {
                $this->logManager->debug(
                    'An order is in preparation, not possible to update the quote',
                    [
                        'extra' => [
                            'link_id' => $link->getId(),
                            'quote_id' => $link->getQuoteId(),
                            'qliro_order_id' => $qliroOrderId,
                        ],
                    ]
                );
            }
        } catch (AlreadyPlacedException $e) {
            throw $e;
        }
        catch (\Exception $exception) {
            $this->logManager->debug(
                $exception,
                [
                    'extra' => [
                        'link_id' => $link->getId(),
                        'quote_id' => $link->getQuoteId(),
                        'qliro_order_id' => $qliroOrderId ?? null,
                    ],
                ]
            );

            throw new TerminalException('Couldn\'t fetch the QliroOne order.', $exception->getCode(), $exception);
        } finally {
            $this->logManager->setMark(null);
        }

        return $qliroOrder;
    }

    /**
     * Update quote with received data in the container and validate QliroOne order
     *
     * @param \Qliro\QliroOne\Api\Data\ValidateOrderNotificationInterface $validateContainer
     * @return \Qliro\QliroOne\Api\Data\ValidateOrderResponseInterface
     */
    public function validate(ValidateOrderNotificationInterface $validateContainer)
    {
        /** @var \Qliro\QliroOne\Api\Data\ValidateOrderResponseInterface $responseContainer */
        $responseContainer = $this->containerMapper->fromArray(
            ['DeclineReason' => ValidateOrderResponseInterface::REASON_OTHER],
            ValidateOrderResponseInterface::class
        );

        try {
            $link = $this->linkRepository->getByQliroOrderId($validateContainer->getOrderId());
            $this->logManager->setMerchantReference($link->getReference());

            try {
                $this->setQuote($this->quoteRepository->get($link->getQuoteId()));
                $this->quoteFromValidateConverter->convert($validateContainer, $this->getQuote());
                $this->quoteManagement->setQuote($this->getQuote())->recalculateAndSaveQuote();

                return $this->validateOrderBuilder->setQuote($this->getQuote())->setValidationRequest(
                    $validateContainer
                )->create();
            } catch (\Exception $exception) {
                $this->logManager->critical(
                    $exception,
                    [
                        'extra' => [
                            'qliro_order_id' => $validateContainer->getOrderId(),
                            'quote_id' => $link->getQuoteId(),
                        ],
                    ]
                );

                return $responseContainer;
            }
        } catch (\Exception $exception) {
            $this->logManager->critical(
                $exception,
                [
                    'extra' => [
                        'qliro_order_id' => $validateContainer->getOrderId(),
                    ],
                ]
            );

            return $responseContainer;
        }
    }

    /**
     * Cancel QliroOne order
     *
     * @param int $qliroOrderId
     * @return \Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     */
    public function cancel($qliroOrderId)
    {
        $this->logManager->setMark('CANCEL QLIRO ORDER');

        $responseContainer = null; // Logical placeholder, returning null may never happen

        try {
            /** @var \Qliro\QliroOne\Model\QliroOrder\Admin\CancelOrderRequest $request */
            $request = $this->containerMapper->fromArray(
                ['OrderId' => $qliroOrderId],
                CancelOrderRequest::class
            );

            $link = false;

            foreach ([true, false] as $flag) {
                try {
                    $link = $this->linkRepository->getByQliroOrderId($qliroOrderId, $flag);
                    break;
                } catch (NoSuchEntityException $e) {
                    continue;
                }
            }

            if (!$link) {
                throw new \LogicException('Couldn\'t fetch the QliroOne order.');
            }

            if ($link->getOrderId()) {
                $order = $this->orderRepository->get($link->getOrderId());
                $storeId = $order->getStoreId();
            } else {
                $quote = $this->quoteRepository->get($link->getQuoteId());
                $storeId = $quote->getStoreId();
            }

            $responseContainer = $this->orderManagementApi->cancelOrder($request, $storeId);

            /** @var \Qliro\QliroOne\Model\OrderManagementStatus $omStatus */
            $omStatus = $this->orderManagementStatusInterfaceFactory->create();

            $omStatus->setRecordType(OrderManagementStatusInterface::RECORD_TYPE_CANCEL);
            $omStatus->setRecordId($link->getOrderId());
            $omStatus->setTransactionId($responseContainer->getPaymentTransactionId());
            $omStatus->setTransactionStatus($responseContainer->getStatus());
            $omStatus->setNotificationStatus(OrderManagementStatusInterface::NOTIFICATION_STATUS_DONE);
            $omStatus->setMessage('Cancellation requested');
            $omStatus->setQliroOrderId($qliroOrderId);
            $this->orderManagementStatusRepository->save($omStatus);

            $link->setIsActive(false);
            $this->linkRepository->save($link);
        } catch (\LogicException $exception) {
            throw new TerminalException(
                'Couldn\'t request to cancel QliroOne order. No link found',
                $exception->getCode(),
                $exception
            );
        } catch (\Exception $exception) {
            $logData = [
                'qliro_order_id' => $qliroOrderId,
            ];

            if (isset($omStatus)) {
                $logData = array_merge($logData, [
                    'transaction_id' => $omStatus->getTransactionId(),
                    'transaction_status' => $omStatus->getTransactionStatus(),
                    'record_type' => $omStatus->getRecordType(),
                    'record_id' => $omStatus->getRecordId(),
                ]);
            }

            $this->logManager->critical(
                $exception,
                [
                    'extra' => $logData,
                ]
            );

            throw new TerminalException('Couldn\'t request to cancel QliroOne order.', $exception->getCode(), $exception);
        } finally {
            $this->logManager->setMark(null);
        }

        return $responseContainer;
    }
}
