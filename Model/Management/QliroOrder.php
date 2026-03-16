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
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterfaceFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Exception\AlreadyPlacedException;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Payload\PayloadConverter;
use Qliro\QliroOne\Model\QliroOrder\Admin\CancelOrderRequest;
use Qliro\QliroOne\Model\QliroOrder\Builder\ValidateOrderBuilder;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromOrderConverter;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromValidateConverter;
use Qliro\QliroOne\Model\ResourceModel\Lock;

/**
 * QliroOne order management.
 *
 * Handles fetching, validating and cancelling Qliro orders.
 * The quote is passed explicitly to every method that needs it.
 * No mutable shared state; does not extend AbstractManagement.
 */
class QliroOrder
{
    public function __construct(
        private readonly Config $qliroConfig,
        private readonly MerchantInterface $merchantApi,
        private readonly OrderManagementInterface $orderManagementApi,
        private readonly ValidateOrderBuilder $validateOrderBuilder,
        private readonly QuoteFromValidateConverter $quoteFromValidateConverter,
        private readonly QuoteFromOrderConverter $quoteFromOrderConverter,
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly PayloadConverter $payloadConverter,
        private readonly LogManager $logManager,
        private readonly Lock $lock,
        private readonly OrderManagementStatusInterfaceFactory $orderManagementStatusInterfaceFactory,
        private readonly OrderManagementStatusRepositoryInterface $orderManagementStatusRepository,
        private readonly Quote $quoteManagement
    ) {
    }

    /**
     * Fetch the Qliro order for the given quote and return it as an array.
     *
     * Also hydrates the quote with customer / address data from the Qliro response
     * when no Magento order has been placed yet.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param bool $allowRecreate
     * @return array
     * @throws AlreadyPlacedException
     * @throws TerminalException
     */
    public function get(\Magento\Quote\Model\Quote $quote, bool $allowRecreate = true): array
    {
        $quoteId = $quote->getEntityId();

        try {
            $existingLink = $this->linkRepository->getByQuoteId($quoteId);
            $isNewOrder   = empty($existingLink->getQliroOrderId());
        } catch (NoSuchEntityException $e) {
            $isNewOrder = true;
        }

        $link = $this->quoteManagement->getLinkFromQuote($quote);
        $this->logManager->debug('Link from quote:', ['extra' => [
            'link_id'        => $link->getId(),
            'quote_id'       => $link->getQuoteId(),
            'qliro_order_id' => $link->getQliroOrderId(),
            'is_new_order'   => $isNewOrder,
        ]]);
        $this->logManager->setMark('GET QLIRO ORDER');

        $qliroOrder = null;

        try {
            $qliroOrderId = $link->getQliroOrderId();

            if (empty($qliroOrderId)) {
                throw new TerminalException(
                    'Link exists but has no Qliro order ID — order creation must have failed previously.'
                );
            }

            if ($isNewOrder) {
                try {
                    $qliroOrderData = $this->merchantApi->getOrder($qliroOrderId);
                } catch (\Exception $firstAttemptException) {
                    $this->logManager->debug(
                        'getOrder failed on fresh order, retrying after delay: '
                        . $firstAttemptException->getMessage()
                    );
                    usleep(500000);
                    $qliroOrderData = $this->merchantApi->getOrder($qliroOrderId);
                }
            } else {
                $qliroOrderData = $this->merchantApi->getOrder($qliroOrderId);
            }

            $qliroOrder = $qliroOrderData;

            if ($this->lock->lock($qliroOrderId)) {
                if (empty($link->getOrderId())) {
                    if (isset($qliroOrder['IsPlaced']) && $qliroOrder['IsPlaced']) {
                        $this->lock->unlock($qliroOrderId);
                        $this->logManager->debug('Order has already been placed:', ['extra' => [
                            'qliro_order_id' => $qliroOrder['OrderId'],
                            'quote_id'       => $link->getQuoteId(),
                        ]]);
                        throw new AlreadyPlacedException('Order has already been placed.');
                    }

                    if (isset($qliroOrder['IsRefused']) && $qliroOrder['IsRefused'] && $allowRecreate) {
                        $link->setIsActive(false);
                        $link->setMessage('Refused order. Create new order');
                        $link->setQliroOrderStatus($qliroOrder['CustomerCheckoutStatus']);
                        $this->linkRepository->save($link);
                        $this->logManager->debug('Refused order detected. New order creation triggered.', [
                            'extra' => [
                                'link_id'        => $link->getId(),
                                'quote_id'       => $link->getQuoteId(),
                                'qliro_order_id' => $qliroOrderId,
                            ],
                        ]);

                        return $this->get($quote, false);
                    }

                    try {
                        $this->quoteFromOrderConverter->convert($qliroOrder, $quote);
                        $this->logManager->debug(
                            'Convert update shipping methods request into quote: ' . $qliroOrder['OrderId']
                        );
                        $this->quoteManagement->recalculateAndSaveQuote($quote);
                    } catch (\Exception $exception) {
                        $this->logManager->debug($exception, ['extra' => [
                            'link_id'        => $link->getId(),
                            'quote_id'       => $link->getQuoteId(),
                            'qliro_order_id' => $qliroOrderId,
                        ]]);
                        $this->lock->unlock($qliroOrderId);
                        throw $exception;
                    }
                }

                $this->lock->unlock($qliroOrderId);
            } else {
                $this->logManager->debug(
                    'An order is in preparation, not possible to update the quote',
                    ['extra' => [
                        'link_id'        => $link->getId(),
                        'quote_id'       => $link->getQuoteId(),
                        'qliro_order_id' => $qliroOrderId,
                    ]]
                );
            }
        } catch (AlreadyPlacedException $e) {
            throw $e;
        } catch (\Exception $exception) {
            $this->logManager->debug($exception, ['extra' => [
                'link_id'        => $link->getId(),
                'quote_id'       => $link->getQuoteId(),
                'qliro_order_id' => $qliroOrderId ?? null,
            ]]);

            throw new TerminalException(
                'Couldn\'t fetch the QliroOne order.',
                $exception->getCode(),
                $exception
            );
        } finally {
            $this->logManager->setMark(null);
        }

        return $qliroOrder;
    }

    /**
     * Validate the Qliro order and apply customer / address data to the quote.
     *
     * @param array $validateContainer
     * @return array
     */
    public function validate(array $validateContainer): array
    {
        $responseContainer = ['DeclineReason' => 'Other'];

        try {
            $link = $this->linkRepository->getByQliroOrderId($validateContainer['OrderId'] ?? null);
            $this->logManager->setMerchantReference($link->getReference());

            try {
                $quote = $this->quoteRepository->get($link->getQuoteId());
                $this->quoteFromValidateConverter->convert($validateContainer, $quote);

                return $this->validateOrderBuilder->setQuote($quote)->setValidationRequest(
                    $validateContainer
                )->create();
            } catch (\Exception $exception) {
                $this->logManager->critical($exception, ['extra' => [
                    'qliro_order_id' => $validateContainer['OrderId'] ?? null,
                    'quote_id'       => $link->getQuoteId(),
                ]]);

                return $responseContainer;
            }
        } catch (\Exception $exception) {
            $this->logManager->critical($exception, ['extra' => [
                'qliro_order_id' => $validateContainer['OrderId'] ?? null,
            ]]);

            return $responseContainer;
        }
    }

    /**
     * Cancel a Qliro order.
     *
     * @param int $qliroOrderId
     * @return \Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface
     * @throws TerminalException
     */
    public function cancel(int $qliroOrderId): \Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface
    {
        $this->logManager->setMark('CANCEL QLIRO ORDER');

        $responseContainer = null;

        try {
            /** @var CancelOrderRequest $request */
            $request = $this->payloadConverter->fromArray(
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
                $order   = $this->orderRepository->get($link->getOrderId());
                $storeId = $order->getStoreId();
            } else {
                $quote   = $this->quoteRepository->get($link->getQuoteId());
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
            $logData = ['qliro_order_id' => $qliroOrderId];

            if (isset($omStatus)) {
                $logData = array_merge($logData, [
                    'transaction_id'     => $omStatus->getTransactionId(),
                    'transaction_status' => $omStatus->getTransactionStatus(),
                    'record_type'        => $omStatus->getRecordType(),
                    'record_id'          => $omStatus->getRecordId(),
                ]);
            }

            $this->logManager->critical($exception, ['extra' => $logData]);

            throw new TerminalException(
                'Couldn\'t request to cancel QliroOne order.',
                $exception->getCode(),
                $exception
            );
        } finally {
            $this->logManager->setMark(null);
        }

        return $responseContainer;
    }
}
