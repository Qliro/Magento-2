<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
namespace Qliro\QliroOne\Model\QliroOrder\Admin\Builder;

use Magento\Framework\Exception\NoSuchEntityException;
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
    const MERCHANT_REFERENCE_CODE_FIELD = 'PARTIAL_DISCOUNT_REFUND_%s';

    /**
     * @var Payment
     */
    private $payment;

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
        }

        $request = $this->prepareRequest();

        $this->payment = null;

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
                [$this->getAdditions()]
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
     * @return QliroOrderItemInterface The Qliro order item populated with discount information, including VAT rate calculation, price, and description.
     */
    private function getOrderItems(): QliroOrderItemInterface
    {
        $discountIncVat = (float)$this->payment->getCreditmemo()->getDiscountAmount();
        $discountTax = -abs((float)$this->payment->getCreditmemo()->getDiscountTaxCompensationAmount());
        $discountExVat = $discountIncVat - $discountTax;
        $vatPercent = abs($discountExVat) > 0
            ? ((abs($discountIncVat) / abs($discountExVat)) - 1) * 100
            : 0;

        /** @var QliroOrderItemInterface $additions */
        $orderItems = $this->qliroOrderItemFactory->create();
        $orderItems->setMerchantReference(
            sprintf(self::MERCHANT_REFERENCE_CODE_FIELD, $this->payment->getCreditmemo()->getInvoiceId())
        )->setType(
            QliroOrderItemInterface::TYPE_DISCOUNT
        )->setQuantity(
            1
        )->setPricePerItemIncVat(
            $discountIncVat
        )->setVatRate(
            $vatPercent
        )->setDescription(
            $this->payment->getCreditmemo()->getDiscountDescription()
        )->setPricePerItemExVat(
            $discountExVat
        );

        return $orderItems;
    }
}
