<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin\Builder;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order\Payment;
use Qliro\QliroOne\Api\Data\AdminReturnWithItemsRequestInterface;
use Qliro\QliroOne\Api\Data\AdminReturnWithItemsRequestInterfaceFactory;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterface;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Model\Api\Client\Exception\ClientException;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\ShippingFeeHandler;
use Magento\Quote\Api\CartRepositoryInterface;
use Qliro\QliroOne\Model\QliroOrder\Builder\CreditMemoItemsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\RefundFeeBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\InvoiceFeeHandler;
use Qliro\QliroOne\Api\Admin\CreditMemo\InvoiceFeeTotalValidatorInterface;
use Qliro\QliroOne\Model\QliroOrder\Builder\RefundDiscountBuilder;

class ReturnWithItemsBuilder
{
    private ?Payment $payment = null;

    public function __construct(
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly LogManager $logManager,
        private readonly Config $qliroConfig,
        private readonly AdminReturnWithItemsRequestInterfaceFactory $adminReturnWithItemsRequestFactory,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly ShippingFeeHandler $shippingFeeHandler,
        private readonly CreditMemoItemsBuilder $creditMemoItemsBuilder,
        private readonly RefundFeeBuilder $refundFeeBuilder,
        private readonly InvoiceFeeHandler $invoiceFeeHandler,
        private readonly InvoiceFeeTotalValidatorInterface $invoiceFeeTotalValidator,
        private readonly RefundDiscountBuilder $refundDiscountBuilder,
        private readonly OrderManagementStatusRepositoryInterface $omStatusRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }


    /**
     * @return AdminReturnWithItemsRequestInterface
     */
    public function create(): AdminReturnWithItemsRequestInterface
    {
        if (empty($this->payment)) {
            throw new \LogicException('Payment entity is not set.');
        }

        $request = $this->prepareRequest();

        $this->payment = null;

        return $request;
    }

    /**
     * @param Payment $payment
     * @return static
     */
    public function setPayment(Payment $payment): static
    {
        $this->payment = $payment;

        return $this;
    }

    /**
     * @return AdminReturnWithItemsRequestInterface
     */
    private function prepareRequest(): AdminReturnWithItemsRequestInterface
    {
        /** @var AdminReturnWithItemsRequestInterface $request */
        $request = $this->adminReturnWithItemsRequestFactory->create();

        $order = $this->payment->getOrder();
        $order->setFirstCaptureFlag(true);

        try {
            $link = $this->linkRepository->getByOrderId($order->getId());
            $quote = $this->cartRepository->get($order->getQuoteId());

            $orderItems = $this->creditMemoItemsBuilder
                    ->setQuote($quote)
                    ->setCreditMemo($this->payment->getCreditmemo())
                    ->create();

            if ($this->payment->getCreditmemo()->getShippingAmount() > 0) {
                $orderItems =  $this->shippingFeeHandler->handle($orderItems, $order);
            }

            if ($this->invoiceFeeTotalValidator->setCreditMemo(
                $this->payment->getCreditmemo())->validate(true, true)
            ) {
                $orderItems = $this->invoiceFeeHandler->handle($orderItems, $order);
            }

            $request->setMerchantApiKey(
                $this->qliroConfig->getMerchantApiKey($order->getStoreId())
            )->setOrderId(
                $link->getQliroOrderId()
            )->setCurrency(
                $order->getOrderCurrencyCode()
            )->setPaymentTransactionId(
                $this->resolvePaymentTransactionId($this->payment, $link->getQliroOrderId())
            )->setOrderItems(
                $orderItems
            )->setFees(
                $this->refundFeeBuilder
                    ->setCreditMemo($this->payment->getCreditmemo())
                    ->create()
            )->setDiscounts(
                $this->refundDiscountBuilder
                    ->setCreditMemo($this->payment->getCreditmemo())
                    ->create()
            );

        } catch (NoSuchEntityException|ClientException $e) {
            $this->logManager->debug(
                $e,
                [
                    'extra' => [
                        'order_id' => $order->getId(),
                        'quote_id' => $order->getQuoteId(),
                    ],
                ]
            );
        }

        return $request;
    }

    /**
     * Resolve the Qliro PaymentTransactionId needed for ReturnWithItems.
     *
     * The happy-path capture stores a numeric Qliro ID as the Magento capture
     * transaction ID. When it is a plain integer string, we use it directly.
     *
     * When the capture resulted in a Magento-generated string ID (e.g.
     * "qliroone-xxxxxx-capture") the Qliro ID is either stored in the
     * qliroone_order_management_status table by captureByInvoice() /
     * captureByShipment(), or was delivered later via an async Qliro webhook.
     * We search that table by qliro_order_id, skipping only refund records.
     */
    private function resolvePaymentTransactionId(Payment $payment, int $qliroOrderId): int
    {
        // Happy path: the capture transaction ID is already the numeric Qliro ID.
        $parentTxnId = $payment->getParentTransactionId();
        if ($parentTxnId !== null && ctype_digit((string) $parentTxnId)) {
            return (int) $parentTxnId;
        }

        // Fallback: look up the Qliro PaymentTransactionId from the OM status table.
        try {
            $criteria = $this->searchCriteriaBuilder
                ->addFilter(OrderManagementStatusInterface::FIELD_QLIRO_ORDER_ID, $qliroOrderId, 'eq')
                ->create();

            foreach ($this->omStatusRepository->getList($criteria)->getItems() as $omStatus) {
                if (!$omStatus->getTransactionId()) {
                    continue;
                }
                // Skip refund records — we want the capture PaymentTransactionId.
                if ($omStatus->getRecordType() === OrderManagementStatusInterface::RECORD_TYPE_REFUND) {
                    continue;
                }
                return (int) $omStatus->getTransactionId();
            }
        } catch (\Exception $e) {
            $this->logManager->debug($e, [
                'extra' => ['qliro_order_id' => $qliroOrderId],
            ]);
        }

        return 0;
    }
}
