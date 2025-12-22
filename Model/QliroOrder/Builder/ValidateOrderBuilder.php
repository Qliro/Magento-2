<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Validator\Exception;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\SubmitQuoteValidator;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderNotificationInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderResponseInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderResponseInterfaceFactory;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Magento\Quote\Model\CustomerManagement;
use \Qliro\QliroOne\Model\Config;

/**
 * Shipping Methods Builder class
 */
class ValidateOrderBuilder
{
    /**
     * @var ValidateOrderNotificationInterface
     */
    private $validationRequest;

    /**
     * @var \Magento\Quote\Model\Quote
     */
    private $quote;

    /**
     * Inject dependencies
     *
     * @param ValidateOrderResponseInterfaceFactory $validateOrderResponseFactory
     * @param StockRegistryInterface $stockRegistry
     * @param OrderItemsBuilder $orderItemsBuilder
     * @param LogManager $logManager
     * @param SubmitQuoteValidator $submitQuoteValidator
     * @param CustomerManagement $customerManagement
     * @param Config $config
     */
    public function __construct(
        private ValidateOrderResponseInterfaceFactory $validateOrderResponseFactory,
        private StockRegistryInterface $stockRegistry,
        private OrderItemsBuilder $orderItemsBuilder,
        private LogManager $logManager,
        private SubmitQuoteValidator $submitQuoteValidator,
        private CustomerManagement $customerManagement,
        private Config $config
    ) {
    }

    /**
     * Set quote for data extraction
     *
     * @param \Magento\Quote\Api\Data\CartInterface $quote
     * @return $this
     */
    public function setQuote(CartInterface $quote)
    {
        $this->quote = $quote;

        return $this;
    }

    /**
     * Set validation request for data extraction
     *
     * @param ValidateOrderNotificationInterface $validationRequest
     * @return $this
     */
    public function setValidationRequest($validationRequest)
    {
        $this->validationRequest = $validationRequest;

        return $this;
    }

    /**
     * @return \Qliro\QliroOne\Api\Data\ValidateOrderResponseInterface
     */
    public function create()
    {
        if (empty($this->quote)) {
            throw new \LogicException('Quote entity is not set.');
        }

        if (empty($this->validationRequest)) {
            throw new \LogicException('QliroOne validation request is not set.');
        }

        /** @var \Qliro\QliroOne\Api\Data\ValidateOrderResponseInterface $container */
        $container = $this->validateOrderResponseFactory->create();

        $allInStock = $this->checkItemsInStock();

        if (!$allInStock) {
            $this->logManager->debug('Not all products are in stock: ' . $this->quote->getId());
            $this->quote = null;
            $this->validationRequest = null;

            return $container->setDeclineReason(ValidateOrderResponseInterface::REASON_OUT_OF_STOCK);
        }

        if (!$this->isQliroShippingDataValid()) {
            $this->quote = null;
            $this->validationRequest = null;
            $this->logValidateError(
                'create',
                'No shipping method selected in qliro'
            );

            return $container->setDeclineReason(ValidateOrderResponseInterface::REASON_SHIPPING);
        }

        if (!$this->quote->isVirtual() && !$this->quote->getShippingAddress()->getShippingMethod()) {
            $method = $this->quote->getShippingAddress()->getShippingMethod();
            $this->quote = null;
            $this->validationRequest = null;
            $this->logValidateError(
                'create',
                'not a virtual order, invalid shipping method selected',
                ['method' => $method]
            );

            return $container->setDeclineReason(ValidateOrderResponseInterface::REASON_SHIPPING);
        }

        try {
            $this->logManager->debug('Starting to validate address for quote id: ' . $this->quote->getId());
            $this->customerManagement->validateAddresses($this->quote);
        } catch (Exception $e) {
            $this->logManager->debug('Validation address failed for quote id: ' . $this->quote->getId());
            $this->quote = null;
            $this->validationRequest = null;
            $this->logValidateError(
                'create',
                $e->getMessage(),
                ['trace' => $e->getTraceAsString()]
            );

            return $container->setDeclineReason(ValidateOrderResponseInterface::REASON_POSTAL_CODE);
        }

        $orderItemsFromQuote = $this->orderItemsBuilder->setQuote($this->quote)->create();

        $this->logManager->debug('Starting to compare quote and Qliro order items: ' . $this->quote->getId());
        $allMatch = $this->compareQuoteAndQliroOrderItems(
            $orderItemsFromQuote,
            $this->validationRequest->getOrderItems()
        );

        if (!$allMatch) {
            $this->logManager->debug('Not all order lines match: ' . $this->quote->getId());
            $this->quote = null;
            $this->validationRequest = null;
            return $container->setDeclineReason(ValidateOrderResponseInterface::REASON_OTHER);
        }

        try {
            $this->logManager->debug('Starting to validate quote: ' . $this->quote->getId());
            $this->submitQuoteValidator->validateQuote($this->quote);
            $this->logManager->debug('Finished to validate quote: ' . $this->quote->getId());
        } catch (Exception|LocalizedException $e) {
            $this->logManager->debug('Validation failed for quote: ' . $this->quote->getId());
            $this->quote = null;
            $this->validationRequest = null;
            $this->logValidateError(
                'create',
                $e->getMessage(),
                ['trace' => $e->getTraceAsString()]
            );

            return $container->setDeclineReason(ValidateOrderResponseInterface::REASON_OTHER);
        }

        $container->setDeclineReason(null);

        $this->quote = null;
        $this->validationRequest = null;

        return $container;
    }

    /**
     * Validates if the Qliro shipping data is valid based on shipping method, order items, and configuration.
     *
     * @return bool Returns true if the shipping data is valid; otherwise, false.
     */
    private function isQliroShippingDataValid() :bool
    {
        if ($this->quote->isVirtual()) {
            return true;
        }

        $isIngridEnabled = $this->config->isIngridEnabled($this->quote->getStoreId());
        if (!$isIngridEnabled && !$this->validationRequest->getSelectedShippingMethod()) {
            return false;
        }

        $isShippingMethodFound = false;
        foreach ($this->validationRequest->getOrderItems() as $item) {
            if ($item->getType() !== \Qliro\QliroOne\Api\Data\QliroOrderItemInterface::TYPE_SHIPPING) {
                continue;
            }

            $isShippingMethodFound = true;
        }

        if ($isIngridEnabled && !$isShippingMethodFound) {
            return false;
        }

        return true;
    }

    /**
     * Check if any items are out of stock
     *
     * @return bool
     */
    private function checkItemsInStock()
    {
        /** @var \Magento\Quote\Model\Quote\Item $quoteItem */
        foreach ($this->quote->getAllVisibleItems() as $quoteItem) {
            $this->logManager->debug('Getting stock for product id: ' . $quoteItem->getProduct()->getId());
            $stockItem = $this->stockRegistry->getStockItem(
                $quoteItem->getProduct()->getId(),
                $quoteItem->getProduct()->getStore()->getWebsiteId()
            );

            if (!$stockItem->getIsInStock()) {
                $this->logManager->debug('Product id is out of stock: ' . $quoteItem->getProduct()->getId());
                $this->logValidateError(
                    'checkItemsInStock',
                    'not enough stock',
                    ['sku' => $quoteItem->getSku()]
                );
                return false;
            }
        }

        return true;
    }

    /**
     * Return true if the quote items and QliroOne order items match
     *
     * @param QliroOrderItemInterface[] $quoteItems
     * @param QliroOrderItemInterface[] $qliroOrderItems
     * @return bool
     */
    private function compareQuoteAndQliroOrderItems($quoteItems, $qliroOrderItems)
    {
        $hashedQuoteItems = [];
        $hashedQliroItems = [];

        $skipTypes = [QliroOrderItemInterface::TYPE_SHIPPING, QliroOrderItemInterface::TYPE_FEE];

        if (!$quoteItems) {
            $this->logValidateError('compareQuoteAndQliroOrderItems','no Cart Items');
            return false;
        }

        // Gather order items converted from quote and hash them for faster search
        foreach ($quoteItems as $quoteItem) {
            if (!in_array($quoteItem->getType(), $skipTypes)) {
                $hashedQuoteItems[$quoteItem->getMerchantReference()] = $quoteItem;
            }
        }

        if (!$qliroOrderItems) {
            $this->logValidateError('compareQuoteAndQliroOrderItems','no Qliro Items');
            return false;
        }

        // Gather order items from QliroOne order and hash them for faster search, then try to see a diff
        foreach ($qliroOrderItems as $qliroOrderItem) {
            if (!in_array($qliroOrderItem->getType(), $skipTypes)) {
                $hash = $qliroOrderItem->getMerchantReference();
                if ($qliroOrderItem->getType() == QliroOrderItemInterface::TYPE_DISCOUNT) {
                    $qliroOrderItem->setPricePerItemExVat(\abs($qliroOrderItem->getPricePerItemExVat()));
                    $qliroOrderItem->setPricePerItemIncVat(\abs($qliroOrderItem->getPricePerItemIncVat()));
                }
                $hashedQliroItems[$hash] = $qliroOrderItem;

                if (!isset($hashedQuoteItems[$hash])) {
                    $this->logValidateError('compareQuoteAndQliroOrderItems','hashedQuoteItems failed');
                    return false;
                }

                if (!$this->compareItems($hashedQuoteItems[$hash], $hashedQliroItems[$hash])) {
                    return false;
                }
            }
        }

        // Try to see a diff between order items converted from quote and from QliroOne order
        foreach ($quoteItems as $quoteItem) {
            if (!in_array($quoteItem->getType(), $skipTypes)) {
                $hash = $quoteItem->getMerchantReference();

                if (!isset($hashedQliroItems[$hash])) {
                    $this->logValidateError('compareQuoteAndQliroOrderItems','$hashedQliroItems failed');
                    return false;
                }

                if (!$this->compareItems($hashedQuoteItems[$hash], $hashedQliroItems[$hash])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Compare two QliroOne order items
     *
     * @param QliroOrderItemInterface $item1
     * @param QliroOrderItemInterface $item2
     * @return bool
     */
    private function compareItems(QliroOrderItemInterface $item1, QliroOrderItemInterface $item2): bool
    {
        if ($item1->getPricePerItemExVat() != $item2->getPricePerItemExVat()) {
            $this->logValidateError(
                'compareItems',
                'pricePerItemExVat different',
                [
                    'item1' => $item1->getPricePerItemExVat(),
                    'item2' => $item2->getPricePerItemExVat()
                ]
            );
            return false;
        }

        if ($item1->getPricePerItemIncVat() != $item2->getPricePerItemIncVat()) {
            $this->logValidateError(
                'compareItems',
                'pricePerItemIncVat different',
                [
                    'item1' => $item1->getPricePerItemIncVat(),
                    'item2' => $item2->getPricePerItemIncVat()
                ]
            );
            return false;
        }

        if ($item1->getQuantity() != $item2->getQuantity()) {
            $this->logValidateError(
                'compareItems',
                'quantity different',
                [
                    'item1' => $item1->getQuantity(),
                    'item2' => $item2->getQuantity()
                ]
            );
            return false;
        }

        if ($item1->getType() != $item2->getType()) {
            $this->logValidateError(
                'compareItems',
                'type different',
                [
                    'item1' => $item1->getType(),
                    'item2' => $item2->getType()
                ]
            );
            return false;
        }

        return true;
    }

    /**
     * @param string $function
     * @param string $reason
     * @param array $details
     */
    private function logValidateError($function, $reason, $details = [])
    {
        $this->logManager->debug(
            'CALLBACK:VALIDATE',
            [
                'extra' => [
                    'function' => $function,
                    'reason' => $reason,
                    'details' => $details,
                ],
            ]
        );
    }
}
