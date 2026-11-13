<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin\Builder;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order\Payment;
use Qliro\QliroOne\Api\Data\AdminAddItemsToInvoiceRequestInterface;
use Qliro\QliroOne\Api\Data\AdminAddItemsToInvoiceRequestInterfaceFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\Api\RequestId;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Api\Data\AdminAdditionsInterface;
use Qliro\QliroOne\Api\Data\AdminAdditionsInterfaceFactory;
use \Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use \Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Model\QliroOrder\Admin\CreditMemo\RefundVatSplit;
use Qliro\QliroOne\Model\QliroOrder\LineVatRate;

class AddItemsToInvoiceBuilder
{
    const MERCHANT_REFERENCE_CODE_FIELD = 'Refund';

    /**
     * @var Payment
     */
    private $payment;

    /**
     * Refund allocation across captures, list of ['payment_transaction_id' => int, 'amount' => float]
     *
     * @var array
     */
    private $allocation = [];

    /**
     * @var RefundVatSplit
     */
    private RefundVatSplit $refundVatSplit;

    /**
     * @param AdminAddItemsToInvoiceRequestInterfaceFactory $adminAddItemsToInvoiceRequestFactory
     * @param LinkRepositoryInterface $linkRepository
     * @param Manager $logManager
     * @param Config $qliroConfig
     * @param AdminAdditionsInterfaceFactory $adminAdditionsFactory
     * @param QliroOrderItemInterfaceFactory $qliroOrderItemFactory
     * @param LineVatRate $lineVatRate
     * @param RequestId $requestId
     * @param RefundVatSplit|null $refundVatSplit
     */
    public function __construct(
        private readonly AdminAddItemsToInvoiceRequestInterfaceFactory $adminAddItemsToInvoiceRequestFactory,
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly Manager $logManager,
        private readonly Config $qliroConfig,
        private readonly AdminAdditionsInterfaceFactory $adminAdditionsFactory,
        private readonly QliroOrderItemInterfaceFactory $qliroOrderItemFactory,
        private readonly LineVatRate $lineVatRate,
        private readonly RequestId $requestId,
        ?RefundVatSplit $refundVatSplit = null
    )
    {
        $this->refundVatSplit = $refundVatSplit ?? new RefundVatSplit();
    }

    /**
     * Creates and returns an AdminAddItemsToInvoiceRequestInterface instance.
     *
     * @return AdminAddItemsToInvoiceRequestInterface The created request object.
     * @throws \LogicException If the payment entity is not set.
     */
    public function create(): AdminAddItemsToInvoiceRequestInterface
    {
        if (empty($this->payment)) {
            throw new \LogicException('Payment entity is not set.');
        };

        $request = $this->prepareRequest();

        $this->payment = null;
        $this->allocation = [];

        return $request;
    }

    /**
     * @param Payment $payment The payment instance to set.
     * @return self Returns the current instance for method chaining.
     */
    public function setPayment(Payment $payment): self
    {
        $this->payment = $payment;

        return $this;
    }

    /**
     * Set the refund allocation across capture transactions.
     * When set, one Addition is built per capture. When empty, falls back to a single
     * Addition against the payment's parent transaction (legacy behavior).
     *
     * @param array $allocation List of ['payment_transaction_id' => int, 'amount' => float]
     * @return self
     */
    public function setAllocation(array $allocation): self
    {
        $this->allocation = $allocation;

        return $this;
    }

    /**
     * Prepares the AdminAddItemsToInvoiceRequestInterface request object.
     *
     * @return AdminAddItemsToInvoiceRequestInterface The prepared request object containing merchant API key,
     *         order ID, currency, and additional details.
     */
    private function prepareRequest(): AdminAddItemsToInvoiceRequestInterface
    {
        /** @var AdminAddItemsToInvoiceRequestInterface $request */
        $request = $this->adminAddItemsToInvoiceRequestFactory->create();

        try {
            $order = $this->payment->getOrder();
            $link = $this->linkRepository->getByOrderId($order->getId());

            $request->setMerchantApiKey(
                $this->qliroConfig->getMerchantApiKey($order->getStoreId())
            )->setOrderId(
                $this->toId($link->getQliroOrderId())
            )->setCurrency(
                $order->getOrderCurrencyCode()
            )->setAdditions(
                $this->buildAdditions()
            )->setRequestId(
                $this->buildRequestId($order)
            );
        } catch (NoSuchEntityException $e) {
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
     * The id this refund is sent under, the same one if the merchant sends it again
     *
     * A refund that timed out after Qliro booked it leaves no credit memo behind, Magento rolls
     * it back, and the merchant refunds again. Under a fresh id Qliro would book that twice, so
     * the id is built from what is being asked for and repeats itself: the order, what it had
     * already given back, and the captures and amounts this refund is spread over.
     *
     * @param \Magento\Sales\Model\Order $order
     * @return string
     */
    private function buildRequestId($order): string
    {
        $parts = [
            'add-items-to-invoice',
            (string)$order->getIncrementId(),
            (float)$order->getTotalRefunded(),
        ];

        if (empty($this->allocation)) {
            $creditMemo = $this->payment->getCreditmemo();

            $parts[] = (string)$this->payment->getParentTransactionId();
            $parts[] = $creditMemo ? abs((float)$creditMemo->getGrandTotal()) : 0.0;

            return $this->requestId->forRequest($parts);
        }

        foreach ($this->allocation as $entry) {
            $parts[] = (string)($entry['payment_transaction_id'] ?? '');
            $parts[] = abs((float)($entry['amount'] ?? 0));
        }

        return $this->requestId->forRequest($parts);
    }

    /**
     * Builds the Additions array for the request.
     *
     * When an allocation is set, one Addition is created per capture transaction, each with
     * its allocated portion of the refund. Qliro validates every Addition against the amount
     * left in its own capture, so a refund exceeding a single capture must be spread across
     * several captures. Without an allocation, a single Addition against the payment's parent
     * transaction is created (legacy behavior for orders without captured-amount tracking).
     *
     * @return AdminAdditionsInterface[]
     */
    private function buildAdditions(): array
    {
        if (empty($this->allocation)) {
            return [$this->getAdditions()];
        }

        $additions = [];

        foreach ($this->allocation as $entry) {
            /** @var AdminAdditionsInterface $addition */
            $addition = $this->adminAdditionsFactory->create();
            $addition->setPaymentTransactionId(
                (int)$entry['payment_transaction_id']
            )->setOrderItems(
                $this->getOrderItems((float)$entry['amount'], $this->entrySplit($entry))
            );

            $additions[] = $addition;
        }

        return $additions;
    }

    /**
     * Creates and returns an instance of AdminAdditionsInterface.
     * The additions object is populated with the associated payment transaction ID
     * and order items before being returned.
     *
     * @return AdminAdditionsInterface
     */
    private function getAdditions(): AdminAdditionsInterface
    {
        /** @var AdminAdditionsInterface $additions */
        $additions = $this->adminAdditionsFactory->create();
        $additions->setPaymentTransactionId(
            $this->toId($this->payment->getParentTransactionId())
        )->setOrderItems(
            $this->getOrderItems((float)$this->payment->getCreditmemo()->getGrandTotal())
        );

        return $additions;
    }

    /**
     * The VAT split of one allocation entry, entries queued before 1.8.57 carry one `vat_rate`
     *
     * @param array $entry
     * @return array|null Null when the credit memo in context decides
     */
    private function entrySplit(array $entry): ?array
    {
        if (isset($entry['vat_split']) && is_array($entry['vat_split'])) {
            return $entry['vat_split'];
        }

        if (isset($entry['vat_rate'])) {
            return [['vat_rate' => (float)$entry['vat_rate'], 'amount' => abs((float)$entry['amount'])]];
        }

        return null;
    }

    /**
     * The refund as negative discount lines, one per VAT rate it falls under
     *
     * @param float $amount Refund portion for these lines, either sign
     * @param array|null $split List of ['vat_rate' => float, 'amount' => float]; null reads it from the credit memo
     * @return QliroOrderItemInterface[]
     */
    private function getOrderItems(float $amount, ?array $split = null): array
    {
        $split = $split ?? $this->refundVatSplit->apportion(
            $this->refundVatSplit->forCreditMemo($this->payment->getCreditmemo()),
            $amount
        );

        $lines = [];

        foreach ($split as $part) {
            $vatRate = (float)$part['vat_rate'];
            $priceIncVat = -abs((float)$part['amount']);

            /** @var QliroOrderItemInterface $orderItem */
            $orderItem = $this->qliroOrderItemFactory->create();

            $orderItem
                // The rate is in the reference even when the refund has one: Qliro keeps the first ex VAT
                // price it saw for a reference and inc VAT price on a capture, and refuses the same pair
                // at another rate (ItemAlreadyExistsWithDifferentPriceExcludingVat). A plain `Refund`
                // would meet a 0% one sent before 1.8.57, or an earlier refund of the same sum at
                // another rate.
                ->setMerchantReference(
                    self::MERCHANT_REFERENCE_CODE_FIELD . '-' . round($vatRate, LineVatRate::PRECISION)
                )
                ->setType(QliroOrderItemInterface::TYPE_DISCOUNT)
                ->setQuantity(1)
                ->setPricePerItemIncVat($priceIncVat)
                ->setPricePerItemExVat($this->lineVatRate->exVatFromIncVat($priceIncVat, $vatRate))
                ->setVatRate($vatRate)
                ->setDescription('Refund')
                ->setMetadata(['qliro' => 'checkout']);

            $lines[] = $orderItem;
        }

        return $lines;
    }

    /**
     * The VAT split of each share of the payment's credit memo, to queue with its allocation entry
     *
     * @param Payment $payment
     * @param float[] $amounts
     * @param array $held Per amount key, rate => amount its capture has left; null where not recorded
     * @return array One list of ['vat_rate' => float, 'amount' => float] per amount, same keys
     */
    public function getRefundVatSplits(Payment $payment, array $amounts, array $held = []): array
    {
        return $this->refundVatSplit->forCaptures(
            $this->refundVatSplit->forCreditMemo($payment->getCreditmemo()),
            $amounts,
            $held
        );
    }

    /**
     * Convert a database id to int, leaving anything else to fail rather than truncating it into another id
     *
     * @param mixed $value
     * @return mixed
     */
    private function toId($value)
    {
        return (string)(int)$value === (string)$value ? (int)$value : $value;
    }
}
