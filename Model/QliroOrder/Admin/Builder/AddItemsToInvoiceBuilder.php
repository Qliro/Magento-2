<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
namespace Qliro\QliroOne\Model\QliroOrder\Admin\Builder;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Payment;
use Qliro\QliroOne\Api\Data\AdminAddItemsToInvoiceRequestInterface;
use Qliro\QliroOne\Api\Data\AdminAddItemsToInvoiceRequestInterfaceFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Api\Data\AdminAdditionsInterface;
use Qliro\QliroOne\Api\Data\AdminAdditionsInterfaceFactory;
use \Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use \Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;

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
     * @param AdminAddItemsToInvoiceRequestInterfaceFactory $adminAddItemsToInvoiceRequestFactory
     * @param LinkRepositoryInterface $linkRepository
     * @param Manager $logManager
     * @param Config $qliroConfig
     * @param AdminAdditionsInterfaceFactory $adminAdditionsFactory
     * @param QliroOrderItemInterfaceFactory $qliroOrderItemFactory
     */
    public function __construct(
        private readonly AdminAddItemsToInvoiceRequestInterfaceFactory $adminAddItemsToInvoiceRequestFactory,
        private readonly LinkRepositoryInterface $linkRepository,
        private readonly Manager $logManager,
        private readonly Config $qliroConfig,
        private readonly AdminAdditionsInterfaceFactory $adminAdditionsFactory,
        private readonly QliroOrderItemInterfaceFactory $qliroOrderItemFactory
    )
    {

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
                $link->getQliroOrderId()
            )->setCurrency(
                $order->getOrderCurrencyCode()
            )->setAdditions(
                $this->buildAdditions()
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
                [$this->getOrderItems((float)$entry['amount'])]
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
            $this->payment->getParentTransactionId()
        )->setOrderItems(
            [$this->getOrderItems()]
        );

        return $additions;
    }

    /**
     * Creates and returns a Qliro order item representing the discount details from the credit memo.
     *
     * @param float|null $amount Refund portion (positive) for this line; defaults to the credit memo grand total.
     * @return QliroOrderItemInterface The Qliro order item populated with discount information, including VAT rate calculation, price, and description.
     */
    private function getOrderItems(?float $amount = null): QliroOrderItemInterface
    {
        $creditMemo = $this->payment->getCreditmemo();

        $amount = $amount ?? (float)$creditMemo->getGrandTotal();
        $priceIncVat = round(-abs($amount), 2);
        $vatRate = $this->getCreditMemoVatRate($creditMemo);
        $priceExVat = round($priceIncVat / (1 + ($vatRate / 100)), 2);

        /** @var QliroOrderItemInterface $orderItems */
        $orderItems = $this->qliroOrderItemFactory->create();

        $orderItems
            ->setMerchantReference(self::MERCHANT_REFERENCE_CODE_FIELD)
            ->setType(QliroOrderItemInterface::TYPE_DISCOUNT)
            ->setQuantity(1)
            ->setPricePerItemIncVat($priceIncVat)
            ->setPricePerItemExVat($priceExVat)
            ->setVatRate(round($vatRate, 2))
            ->setDescription('Refund')
            ->setMetadata(['qliro' => 'checkout']);

        return $orderItems;
    }

    /**
     * Calculate the VAT (tax) rate for a given credit memo based on its items.
     *
     * @param Creditmemo $creditMemo The credit memo instance for which to determine the VAT rate.
     * @return float The VAT rate as a percentage. Returns 0.00 if multiple or no VAT rates are found.
     */
    private function getCreditMemoVatRate(Creditmemo $creditMemo): float
    {
        $rates = [];

        foreach ($creditMemo->getAllItems() as $creditMemoItem) {
            if ($creditMemoItem->getOrderItem()->isDummy()) {
                continue;
            }

            if ((float)$creditMemoItem->getQty() <= 0) {
                continue;
            }

            $taxPercent = $creditMemoItem->getOrderItem()->getTaxPercent();

            if ($taxPercent !== null) {
                $rates[] = round((float)$taxPercent, 2);
            }
        }

        $rates = array_unique($rates);

        if (count($rates) === 1) {
            return (float)reset($rates);
        }

        return 0.00;
    }
}
