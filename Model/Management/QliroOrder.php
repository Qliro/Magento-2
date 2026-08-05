<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Management;

use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Qliro\QliroOne\Api\Client\MerchantInterface;
use Qliro\QliroOne\Api\Client\OrderManagementInterface;
use Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterfaceFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Model\Exception\AlreadyPlacedException;
use Qliro\QliroOne\Model\Exception\TerminalException;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\OrderManagementStatus;
use Qliro\QliroOne\Model\Payload\PayloadConverter;
use Qliro\QliroOne\Model\QliroOrder\Admin\CancelOrderRequest;
use Qliro\QliroOne\Model\QliroOrder\Builder\ValidateOrderBuilder;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromOrderConverter;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromValidateConverter;
use Qliro\QliroOne\Model\Quote\ItemsLimitValidator;
use Qliro\QliroOne\Model\ResourceModel\Lock;

/**
 * QliroOne order management.
 *
 * Handles fetching, validating, and cancelling Qliro orders.
 * The quote is passed explicitly to every method that needs it.
 * No mutable shared state; does not extend AbstractManagement.
 */
class QliroOrder
{
    /**
     * Class constructor
     *
     * @param MerchantInterface $merchantApi
     * @param OrderManagementInterface $orderManagementApi
     * @param ValidateOrderBuilder $validateOrderBuilder
     * @param QuoteFromValidateConverter $quoteFromValidateConverter
     * @param QuoteFromOrderConverter $quoteFromOrderConverter
     * @param LinkRepositoryInterface $linkRepository
     * @param CartRepositoryInterface $quoteRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param PayloadConverter $payloadConverter
     * @param LogManager $logManager
     * @param Lock $lock
     * @param OrderManagementStatusInterfaceFactory $orderManagementStatusInterfaceFactory
     * @param OrderManagementStatusRepositoryInterface $orderManagementStatusRepository
     * @param Quote $quoteManagement
     */
    public function __construct(
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
        private readonly Quote $quoteManagement,
        private readonly ItemsLimitValidator $itemsLimitValidator,
        private readonly Config $qliroConfig
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
     * @throws TerminalException|AlreadyExistsException
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
                } catch (\Qliro\QliroOne\Model\Api\Client\Exception\OrderExpiredException $expired) {
                    return $this->recoverFromExpired($quote, $link, $qliroOrderId, $allowRecreate, $expired);
                } catch (\Exception $firstAttemptException) {
                    $this->logManager->debug(
                        'getOrder failed on fresh order, retrying after delay: '
                        . $firstAttemptException->getMessage()
                    );
                    usleep(500000);
                    try {
                        $qliroOrderData = $this->merchantApi->getOrder($qliroOrderId);
                    } catch (\Qliro\QliroOne\Model\Api\Client\Exception\OrderExpiredException $expired) {
                        return $this->recoverFromExpired($quote, $link, $qliroOrderId, $allowRecreate, $expired);
                    }
                }
            } else {
                try {
                    $qliroOrderData = $this->merchantApi->getOrder($qliroOrderId);
                } catch (\Qliro\QliroOne\Model\Api\Client\Exception\OrderExpiredException $expired) {
                    return $this->recoverFromExpired($quote, $link, $qliroOrderId, $allowRecreate, $expired);
                }
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
                        $link->setIsActive(0);
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
                        $this->quoteManagement->updateQliroOrder($quote);
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

            // If the stored order ID is no longer valid in the current API environment
            // (e.g. after a sandbox ↔ production switch) and no Magento order has been
            // placed yet, reset the stale link and create a fresh Qliro order instead of
            // surfacing a terminal error to the customer.
            if ($allowRecreate && !empty($qliroOrderId ?? null) && empty($link->getOrderId())) {
                $this->logManager->debug('Stale or invalid Qliro order ID detected; resetting link and recreating order.', [
                    'extra' => [
                        'link_id'        => $link->getId(),
                        'quote_id'       => $link->getQuoteId(),
                        'stale_order_id' => $qliroOrderId,
                    ],
                ]);
                $link->setQliroOrderId(null);
                $link->setIsActive(0);
                $this->linkRepository->save($link);
                return $this->get($quote, false);
            }

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

                // Final guard against the per-order item limit. The storefront predispatch
                // observer and the sales_model_service_quote_submit_before observer cover
                // the standard flows; this catches direct/custom integrations that bypass
                // them, so we don't charge a customer for an order Qliro would later reject.
                // ItemsLimitValidator logs the violation with quote_id / line_count / store_id.
                try {
                    $this->itemsLimitValidator->validateQuoteItemsLimit($quote);
                } catch (\Magento\Framework\Exception\LocalizedException $limitEx) {
                    return ['DeclineReason' => 'Other'];
                }

                $this->quoteFromValidateConverter->convert($validateContainer, $quote);

                $response = $this->validateOrderBuilder->setQuote($quote)->setValidationRequest(
                    $validateContainer
                )->create();

                // If validation succeeds (no DeclineReason), freeze the quote: lock the link so
                // any subsequent shipping-method / shipping-price callback from Qliro or the
                // iframe is rejected. This prevents Magento ⇄ Qliro shipping drift when a 3rd
                // party (e.g. Ingrid/nShift) mutates shipping AFTER validation.
                if (empty($response['DeclineReason'])) {
                    try {
                        $link->setIsLocked(true);
                        $this->linkRepository->save($link);
                        $this->logManager->debug(
                            'Quote locked after successful validate — shipping/price updates will be denied.',
                            ['extra' => ['qliro_order_id' => $link->getQliroOrderId(), 'quote_id' => $link->getQuoteId()]]
                        );
                    } catch (\Exception $e) {
                        // Non-fatal: lock failure should not abort an otherwise successful validate.
                        $this->logManager->debug($e, ['extra' => ['qliro_order_id' => $link->getQliroOrderId()]]);
                    }
                }

                return $response;
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
     * @return AdminTransactionResponseInterface
     * @throws TerminalException
     */
    public function cancel(int $qliroOrderId): AdminTransactionResponseInterface
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

            /** @var OrderManagementStatus $omStatus */
            $omStatus = $this->orderManagementStatusInterfaceFactory->create();
            $omStatus->setRecordType(OrderManagementStatusInterface::RECORD_TYPE_CANCEL);
            $omStatus->setRecordId($link->getOrderId());
            $omStatus->setTransactionId($responseContainer->getPaymentTransactionId());
            $omStatus->setTransactionStatus($responseContainer->getStatus());
            $omStatus->setNotificationStatus(OrderManagementStatusInterface::NOTIFICATION_STATUS_DONE);
            $omStatus->setMessage('Cancellation requested');
            $omStatus->setQliroOrderId($qliroOrderId);
            $this->orderManagementStatusRepository->save($omStatus);

            $link->setIsActive(0);
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

    /**
     * Recovery path when the Qliro Merchant API responds ORDER_EXPIRED for an order we
     * still hold a reference to. Qliro confirmed: nothing needs to be done with the
     * expired order on their side — it auto-refuses — but a fresh order must use a NEW
     * unique merchant reference.
     *
     * Steps:
     *   1. Deactivate the link (clear qliro_order_id, reference, status) so the next
     *      pass through getLinkFromQuote() creates a brand-new Qliro order.
     *   2. For useIncrementIdAsReference mode, also clear quote.reserved_order_id so a
     *      fresh increment_id is reserved on the recreate (Qliro requires unique
     *      references — we can't reuse the old increment_id).
     *   3. Recurse into get() with allowRecreate=false to prevent infinite loops if the
     *      fresh order somehow expires again on the same call.
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @param \Qliro\QliroOne\Api\Data\LinkInterface $link
     * @param int $expiredQliroOrderId
     * @param bool $allowRecreate
     * @param \Throwable $expired  Original exception (for log context)
     * @return array
     */
    private function recoverFromExpired(
        \Magento\Quote\Model\Quote $quote,
        \Qliro\QliroOne\Api\Data\LinkInterface $link,
        int $expiredQliroOrderId,
        bool $allowRecreate,
        \Throwable $expired
    ): array {
        if (!$allowRecreate) {
            // We already retried — bubble up so the customer sees a clear error rather
            // than looping. Reaching this branch suggests a deeper issue (clock skew,
            // misconfigured reserved_order_id, etc.).
            $this->logManager->critical(
                'Qliro ORDER_EXPIRED on the recovery attempt — giving up.',
                ['extra' => ['expired_qliro_order_id' => $expiredQliroOrderId]]
            );
            throw new TerminalException(
                'Couldn\'t recover from an expired Qliro order.',
                0,
                $expired
            );
        }

        $this->logManager->warning(
            'Qliro ORDER_EXPIRED — deactivating link and creating a fresh Qliro order with a new reference.',
            ['extra' => [
                'expired_qliro_order_id' => $expiredQliroOrderId,
                'quote_id'               => $quote->getId(),
            ]]
        );

        try {
            // Clear only what's strictly required for the recreate flow:
            //   - qliroOrderId: triggers Quote::getLinkFromQuote() to create a fresh Qliro order
            //   - qliroOrderStatus: empty string clears any stale CheckoutStatus from the
            //     expired order so applyQliroOrderStatus() doesn't act on it
            //
            // 'reference' is left alone — it will be overwritten by the new value in
            // Quote::getLinkFromQuote() on the recreate. setReference() / setMessage()
            // are typed `string` (not `?string`), so passing null would TypeError.
            $link->setQliroOrderId(null);
            $link->setQliroOrderStatus('');
            $link->setMessage('Previous Qliro order expired — recreated.');
            $this->linkRepository->save($link);

            // For increment_id mode the same reserved_order_id can't be reused —
            // Qliro requires merchant references to be unique. Clearing it makes
            // Magento reserve a new one on the next reserveOrderId() call.
            if ($this->qliroConfig->useIncrementIdAsReference()) {
                $quote->setReservedOrderId(null);
                $this->quoteRepository->save($quote);
            }
        } catch (\Exception $e) {
            $this->logManager->critical($e, ['extra' => [
                'expired_qliro_order_id' => $expiredQliroOrderId,
            ]]);
            throw new TerminalException(
                'Failed to deactivate expired Qliro link.',
                0,
                $e
            );
        }

        return $this->get($quote, false);
    }
}
