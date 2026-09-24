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
use Qliro\QliroOne\Model\Exception\UnsupportedQuoteException;
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
     * @param bool $allowRecreate
     * @return \Qliro\QliroOne\Api\Data\QliroOrderInterface
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     * @throws \Qliro\QliroOne\Model\Exception\TerminalException
     */
    public function get($allowRecreate = true)
    {
        $link = $this->quoteManagement->setQuote($this->getQuote())->getLinkFromQuote();
        $this->logManager->debug(
            'Link from quote:',
            [
                'extra' => [
                    'link_id' => $link->getId(),
                    'quote_id' => $link->getQuoteId(),
                    'qliro_order_id' => $link->getQliroOrderId(),
                ],
            ]
        );
        $this->logManager->setMark('GET QLIRO ORDER');

        $qliroOrder = null; // Logical placeholder, may never happen

        try {
            $qliroOrderId = $link->getQliroOrderId();
            try {
                $qliroOrder = $this->merchantApi->getOrder($link->getQliroOrderId());
            } catch (\Throwable $e) {
                if (!$this->isOrderNotFound($e)) {
                    throw $e;
                }

                // The local link points to a Qliro order that no longer exists.
                // Deactivate it, get a fresh link (which will create a new Qliro order), and retry
                $this->logManager->debug(
                    'Qliro returned 404 for the stored qliro_order_id; deactivating link and recreating',
                    [
                        'extra' => [
                            'link_id' => $link->getId(),
                            'quote_id' => $link->getQuoteId(),
                            'qliro_order_id' => $qliroOrderId,
                        ],
                    ]
                );

                $link->setIsActive(false);
                $link->setMessage('Qliro order not found, recreating');
                $this->linkRepository->save($link);

                // Re-enter the flow with a fresh link
                $link = $this->quoteManagement->setQuote($this->getQuote())->getLinkFromQuote();
                $qliroOrderId = $link->getQliroOrderId();
                $qliroOrder = $this->merchantApi->getOrder($qliroOrderId);
            }

            if ($this->lock->lock($qliroOrderId)) {
                if (empty($link->getOrderId())) {
                    if ($qliroOrder->isPlaced()) {
                        $this->lock->unlock($qliroOrderId);
                        $this->logManager->debug(
                            'Order has already been placed:',
                            [
                                'extra' => [
                                    'qliro_order_id' => $qliroOrder->getOrderId(),
                                    'quote_id' => $link->getQuoteId(),
                                ],
                            ]
                        );
                        throw new AlreadyPlacedException('Order has already been placed.');
                    }

                    if ($qliroOrder->isRefused() && $allowRecreate) {
                        $link->setIsActive(false);
                        $link->setMessage("Refused order. Create new order");
                        $link->setQliroOrderStatus($qliroOrder->getCustomerCheckoutStatus());
                        $this->linkRepository->save($link);
                        $this->logManager->debug(
                            'Refused order detected. New order creation triggered.',
                            [
                                'extra' => [
                                    'link_id' => $link->getId(),
                                    'quote_id' => $link->getQuoteId(),
                                    'qliro_order_id' => $qliroOrderId,
                                ],
                            ]
                        );

                        return $this->get(false); // Recursion, but will max call it once
                    }

                    try {
                        $isQuoteChanged = $this->quoteFromOrderConverter->convert($qliroOrder, $this->getQuote());
                        $this->logManager->debug('Convert update shipping methods request into quote: ' . $qliroOrder->getOrderId());
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

                // The update above ran before this order was fetched, so it was built from a
                // quote that did not know the customer address yet. Qliro masks that address in
                // the browser payload, so this fetch is the first place it becomes available,
                // and without a second push the checkout keeps the empty shipping method list
                // until the page is reloaded.
                if (!empty($isQuoteChanged)) {
                    $this->logManager->debug(
                        'Qliro order taught the quote something new, pushing the update again',
                        [
                            'extra' => [
                                'quote_id' => $link->getQuoteId(),
                                'qliro_order_id' => $qliroOrderId,
                            ],
                        ]
                    );
                    $this->quoteManagement->setQuote($this->getQuote())->update($qliroOrderId);
                }
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
        } catch (UnsupportedQuoteException $e) {
            // The buyer can only fix a cart they are told about, so this one keeps its message
            // instead of becoming "the checkout failed to load"
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
     * Read the order back when the customer event has left the quote without a destination
     *
     * Qliro withholds the address from that event, sending `{"isMasked": true}` in its place
     * until the buyer has identified, so the merchant API is the only place it can be read. Until
     * it reaches the quote Magento rates nothing, the update carries no shipping methods and the
     * checkout has no delivery to offer. The read used to happen in the cart refresh the browser
     * makes afterwards, which left the timing of the whole delivery step to whichever script owns
     * the customer handler: a store that had replaced it with one polling an endpoint of its own
     * put nine seconds between the event and the address, and the widget had rendered the payment
     * step before the methods arrived. The read belongs to the event that reveals the buyer, and
     * that event arrives whoever owns the handler (PLIN-376).
     *
     * @return void
     */
    public function refreshAfterCustomerEvent(): void
    {
        $quote = $this->getQuote();
        $qliroOrderId = null;

        /*
         * The whole body, not only the read. This answers a customer payload that has already
         * been applied and saved, and the cart refresh the browser makes next reads the order
         * again anyway, so nothing in here may turn an applied payload into a failed update:
         * the controller answers any exception from it with a 400.
         */
        try {
            if ($quote->isVirtual()) {
                return;
            }

            $shippingAddress = $quote->getShippingAddress();

            /*
             * Once the quote can be rated there is nothing left to learn, so the events that
             * follow cost nothing. While the address is still masked every event does pay for a
             * read, which is what buys the delivery step: the cart refresh those same events
             * used to trigger read the order too, and rated the whole quote to hash an update
             * payload on top of it.
             */
            if ($shippingAddress && $shippingAddress->getPostcode() && $shippingAddress->getCountryId()) {
                return;
            }

            $link = $this->linkRepository->getByQuoteId($quote->getId());
            $qliroOrderId = $link->getQliroOrderId();

            // Only for an order that already exists, and only while this quote has not become a
            // Magento order: creating one or placing one is not this event's business
            if (!$qliroOrderId || !empty($link->getOrderId())) {
                return;
            }

            $this->readTheAddressFromQliro($quote, $qliroOrderId);
        } catch (NoSuchEntityException $exception) {
            // A quote Qliro has never heard of, which the cart refresh gives an order
            return;
        } catch (\Throwable $exception) {
            $this->logManager->debug(
                'Could not read the QliroOne order back while answering the customer event',
                [
                    'extra' => [
                        'quote_id' => $quote->getId(),
                        'qliro_order_id' => $qliroOrderId,
                        'reason' => $exception->getMessage(),
                    ],
                ]
            );
        }
    }

    /**
     * Take the address from the QliroOne order and push the methods it makes possible
     *
     * Deliberately not `get()`, although it does the same three things. `get()` reaches them
     * through `getLinkFromQuote()`, which rates the whole quote to hash the update payload
     * whether anything changed or not, and which creates a Qliro order for a quote that has
     * none. This path is on the buyer's critical path and runs while the address is still
     * masked, so it rates once and only because the address is new (PLIN-376).
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param string|int $qliroOrderId
     * @return void
     */
    private function readTheAddressFromQliro($quote, $qliroOrderId): void
    {
        $qliroOrder = $this->merchantApi->getOrder($qliroOrderId);

        // Both belong to the cart refresh, which redirects the buyer to the pending page or
        // replaces the order, and neither is something a customer event should decide
        if ($qliroOrder->isPlaced() || $qliroOrder->isRefused()) {
            return;
        }

        if (!$this->lock->lock($qliroOrderId)) {
            $this->logManager->debug(
                'An order is in preparation, leaving the customer event to the cart refresh',
                ['extra' => ['quote_id' => $quote->getId(), 'qliro_order_id' => $qliroOrderId]]
            );

            return;
        }

        try {
            $isQuoteChanged = $this->quoteFromOrderConverter->convert($qliroOrder, $quote);

            if (!empty($isQuoteChanged)) {
                $this->quoteManagement->setQuote($quote)->recalculateAndSaveQuote();
            }
        } finally {
            $this->lock->unlock($qliroOrderId);
        }

        if (!empty($isQuoteChanged)) {
            $this->quoteManagement->setQuote($quote)->update($qliroOrderId);
        }

        $shippingAddress = $quote->getShippingAddress();

        // Logged whether or not the order carried anything, because the line is what says the
        // read happened at all: a buyer Qliro has not identified yet leaves it with nothing to
        // teach the quote, and that is the same line a support case needs to see
        $this->logManager->debug(
            'Read the QliroOne order back for the address the customer event withheld',
            [
                'extra' => [
                    'quote_id' => $quote->getId(),
                    'qliro_order_id' => $qliroOrderId,
                    'anything_learned' => (bool)$isQuoteChanged,
                    'quote_postcode_set' => (bool)($shippingAddress && $shippingAddress->getPostcode()),
                    'quote_country_set' => (bool)($shippingAddress && $shippingAddress->getCountryId()),
                ],
            ]
        );
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

                $response = $this->validateOrderBuilder->setQuote($this->getQuote())->setValidationRequest(
                    $validateContainer
                )->create();

                if (!$response->getDeclineReason()) {
                    // The order is on its way to payment from here, so the quote must stop moving.
                    // A failure to write the mark must not turn an approved order into a declined
                    // one, which is what letting it reach the catch below would do.
                    try {
                        $this->linkRepository->markValidated((int)$link->getQuoteId());
                    } catch (\Exception $exception) {
                        $this->logManager->warning(
                            'Could not mark the link as validated: ' . $exception->getMessage()
                        );
                    }
                }

                return $response;
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

    /**
     * Determines if the given exception indicates that an order was not found.
     *
     * @param \Throwable $e The exception to check for a 404 status indicating a missing order.
     * @return bool True if the exception or one of its causes indicates a 404 status; otherwise, false.
     */
    private function isOrderNotFound(\Throwable $e): bool
    {
        $current = $e;
        while ($current !== null) {
            if ($current instanceof \GuzzleHttp\Exception\ClientException) {
                return $current->getResponse()?->getStatusCode() === 404;
            }
            $current = $current->getPrevious();
        }
        return false;
    }
}
