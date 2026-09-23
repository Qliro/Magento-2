<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Framework\App\Area;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Validator\Exception;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\SubmitQuoteValidator;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderNotificationInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderResponseInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderResponseInterfaceFactory;
use Qliro\QliroOne\Api\StockAvailabilityInterface;
use Qliro\QliroOne\Model\Quote\WholeQuantityValidator;
use Qliro\QliroOne\Model\Stock\QuoteLines;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Magento\Quote\Model\CustomerManagement;
use Magento\Store\Model\App\Emulation as StoreEmulation;
use Magento\Store\Model\StoreManagerInterface;
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
     * @var CartRepositoryInterface
     */
    private $quoteRepository;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var StoreEmulation
     */
    private $storeEmulation;

    /**
     * Inject dependencies
     *
     * @param ValidateOrderResponseInterfaceFactory $validateOrderResponseFactory
     * @param StockAvailabilityInterface $stockAvailability
     * @param QuoteLines $quoteLines
     * @param OrderItemsBuilder $orderItemsBuilder
     * @param LogManager $logManager
     * @param SubmitQuoteValidator $submitQuoteValidator
     * @param CustomerManagement $customerManagement
     * @param Config $config
     * @param CartRepositoryInterface $quoteRepository
     * @param StoreManagerInterface $storeManager
     * @param StoreEmulation $storeEmulation
     */
    public function __construct(
        private ValidateOrderResponseInterfaceFactory $validateOrderResponseFactory,
        private StockAvailabilityInterface $stockAvailability,
        private QuoteLines $quoteLines,
        private OrderItemsBuilder $orderItemsBuilder,
        private LogManager $logManager,
        private SubmitQuoteValidator $submitQuoteValidator,
        private CustomerManagement $customerManagement,
        private Config $config,
        private WholeQuantityValidator $wholeQuantityValidator,
        ?CartRepositoryInterface $quoteRepository = null,
        ?StoreManagerInterface $storeManager = null,
        ?StoreEmulation $storeEmulation = null
    ) {
        // Optional so a subclass calling parent::__construct() with the old signature keeps
        // working. Magento passes null for optional arguments instead of resolving them, so
        // the instances are fetched here rather than left to DI.
        $this->quoteRepository = $quoteRepository ?: ObjectManager::getInstance()->get(CartRepositoryInterface::class);
        $this->storeManager = $storeManager ?: ObjectManager::getInstance()->get(StoreManagerInterface::class);
        $this->storeEmulation = $storeEmulation ?: ObjectManager::getInstance()->get(StoreEmulation::class);
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
     * Put the delivery Qliro validates against on the quote, when the quote has lost it
     *
     * Qliro is the authority on what the buyer picked, and the quote can be missing that choice
     * for reasons that are not the buyer's: the rates were collected again under an address that
     * arrived later and dropped the code, or the update carrying it was refused while the quote
     * was closed for changes. Declining there fails a checkout the buyer completed, so the choice
     * is applied here instead, and only stands if the carriers still offer it.
     *
     * @return bool Whether the quote now carries the method Qliro selected
     */
    private function applySelectedShippingMethod(): bool
    {
        $code = $this->validationRequest->getSelectedShippingMethod();

        if (empty($code)) {
            return false;
        }

        $quoteStoreId = (int)$this->quote->getStoreId();
        $isEmulated = false;

        // Rated in the quote's own store view, for the same reason the shipping methods callback
        // is: a carrier that reads the current store would otherwise price this in another
        // store's currency and refuse the code the buyer was offered (PLIN-376).
        // Guarded, because this method answers with false and never throws: a store view removed
        // or disabled between the order's creation and the callback would otherwise turn a
        // shipping decline into a critical and a generic refusal
        try {
            if ($quoteStoreId > 0 && $quoteStoreId !== (int)$this->storeManager->getStore()->getId()) {
                $this->storeEmulation->startEnvironmentEmulation($quoteStoreId, Area::AREA_FRONTEND, true);
                $isEmulated = $quoteStoreId === (int)$this->storeManager->getStore()->getId();
            }
        } catch (\Throwable $exception) {
            $this->logManager->debug(
                'Could not rate in the quote store view: ' . $exception->getMessage()
            );

            return false;
        }

        try {
            $shippingAddress = $this->quote->getShippingAddress();
            $shippingAddress->setCollectShippingRates(true);
            $shippingAddress->collectShippingRates();

            $isOffered = false;

            foreach ($shippingAddress->getAllShippingRates() as $rate) {
                if ($rate->getCode() === $code) {
                    $isOffered = true;

                    break;
                }
            }

            if (!$isOffered) {
                $this->logManager->debug(
                    'CALLBACK:VALIDATE: the method Qliro selected is not among the rates',
                    ['extra' => ['quote_id' => $this->quote->getId(), 'qliro_method' => $code]]
                );

                return false;
            }

            $shippingAddress->setShippingMethod($code);
            $this->quote->setTotalsCollectedFlag(false);
            $this->quote->collectTotals();

            // The buyer pays Qliro's total, so the store may only accept the order when its own
            // price for that delivery agrees. The line comparison above skips shipping lines, so
            // without this a re-rating under a later address could place an order for more than
            // was charged. Nothing is saved when they disagree.
            $qliroPrice = $this->getQliroShippingPrice();
            $quotePrice = (float)$shippingAddress->getShippingInclTax();

            if (\abs($quotePrice - $qliroPrice) >= 0.005) {
                $this->logManager->debug(
                    'CALLBACK:VALIDATE: the store prices that method differently than Qliro',
                    [
                        'extra' => [
                            'quote_id' => $this->quote->getId(),
                            'qliro_method' => $code,
                            'quote_price' => $quotePrice,
                            'qliro_price' => $qliroPrice,
                        ],
                    ]
                );
                $shippingAddress->setShippingMethod(null);

                return false;
            }

            $this->quoteRepository->save($this->quote);

            $this->logManager->debug(
                'CALLBACK:VALIDATE: applied the method Qliro selected to the quote',
                ['extra' => ['quote_id' => $this->quote->getId(), 'qliro_method' => $code]]
            );

            return $shippingAddress->getShippingMethod() === $code;
        } catch (\Exception $exception) {
            // A decline is the honest answer when the quote cannot be brought in line, and it is
            // the answer the caller gives anyway when this returns false.
            $this->logManager->debug(
                'CALLBACK:VALIDATE: could not apply the method Qliro selected: ' . $exception->getMessage()
            );

            return false;
        } finally {
            if ($isEmulated) {
                $this->storeEmulation->stopEnvironmentEmulation();
            }
        }
    }

    /**
     * What Qliro charges the buyer for delivery on this order, VAT included
     *
     * @return float
     */
    private function getQliroShippingPrice(): float
    {
        $total = 0.0;

        foreach ($this->validationRequest->getOrderItems() as $item) {
            if ($item->getType() === QliroOrderItemInterface::TYPE_SHIPPING) {
                $total += $item->getPricePerItemIncVat() * $item->getQuantity();
            }
        }

        return $total;
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

        $fractionalLines = $this->wholeQuantityValidator->fractionalLines($this->quote);

        if ($fractionalLines) {
            $this->quote = null;
            $this->validationRequest = null;
            $this->logValidateError(
                'create',
                'a line Qliro cannot carry the quantity of',
                $fractionalLines
            );

            return $container->setDeclineReason(ValidateOrderResponseInterface::REASON_OTHER);
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

        if (!$this->quote->isVirtual()
            && !$this->quote->getShippingAddress()->getShippingMethod()
            && !$this->applySelectedShippingMethod()
        ) {
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
     * Check whether every line can be sold in the quantity the cart asks for
     *
     * @return bool
     */
    private function checkItemsInStock()
    {
        /*
         * A cart that has already become an order has taken its own stock, and an inventory that
         * counts reservations counts that against it. A validate callback sent again after the
         * order was placed would be refused on the stock the order itself is holding.
         */
        if (!$this->quote->getIsActive()) {
            return true;
        }

        $lines = $this->quoteLines->fromQuote($this->quote);
        $websiteId = (int)$this->quote->getStore()->getWebsiteId();

        foreach ($this->stockAvailability->areSalable($lines, $websiteId) as $sku => $isSalable) {
            if ($isSalable) {
                continue;
            }

            $this->logValidateError(
                'checkItemsInStock',
                'not enough stock',
                ['sku' => $sku, 'qty' => $lines[$sku]['qty'] ?? null]
            );

            return false;
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
                if (!$this->hashLine($hashedQuoteItems, $quoteItem, 'cart')) {
                    return false;
                }
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

                if (!$this->hashLine($hashedQliroItems, $qliroOrderItem, 'Qliro order')) {
                    return false;
                }

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
     * Index a line by its merchant reference, refusing a reference that stands for two lines
     *
     * The comparison below can only tell the two sides apart by this reference, and Qliro merges
     * lines that share one and sums their quantity, so a repeated reference has to end the
     * comparison: whichever line the index kept, the amounts of the other one went unchecked and
     * accepting them is what this callback exists to prevent. The module keeps the reference
     * unique per cart line, so this is a store's own line builder or a third party (PLIN-408).
     *
     * @param QliroOrderItemInterface[] $index
     * @param QliroOrderItemInterface $item
     * @param string $side
     * @return bool
     */
    private function hashLine(array &$index, QliroOrderItemInterface $item, string $side): bool
    {
        $reference = $item->getMerchantReference();

        if (isset($index[$reference])) {
            $this->logValidateError(
                'compareQuoteAndQliroOrderItems',
                'merchant reference stands for more than one line',
                ['side' => $side, 'merchant_reference' => $reference]
            );

            return false;
        }

        $index[$reference] = $item;

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
