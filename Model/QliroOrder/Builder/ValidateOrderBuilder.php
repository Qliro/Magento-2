<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Framework\App\Area;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Validator\Exception;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\SubmitQuoteValidator;
use Magento\Store\Model\App\Emulation;
use Magento\Store\Model\StoreManagerInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderNotificationInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderResponseInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderResponseInterfaceFactory;
use Qliro\QliroOne\Api\StockAvailabilityInterface;
use Qliro\QliroOne\Model\Quote\WholeQuantityValidator;
use Qliro\QliroOne\Model\Stock\QuoteLines;
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
     * @param StockAvailabilityInterface $stockAvailability
     * @param QuoteLines $quoteLines
     * @param OrderItemsBuilder $orderItemsBuilder
     * @param LogManager $logManager
     * @param SubmitQuoteValidator $submitQuoteValidator
     * @param CustomerManagement $customerManagement
     * @param Config $config
     * @param WholeQuantityValidator $wholeQuantityValidator
     * @param CartRepositoryInterface|null $quoteRepository
     * @param StoreManagerInterface|null $storeManager
     * @param Emulation|null $storeEmulation
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
        private ?CartRepositoryInterface $quoteRepository = null,
        private ?StoreManagerInterface $storeManager = null,
        private ?Emulation $storeEmulation = null
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

        $shippingDecline = $this->applySelectedShippingMethod();

        if ($shippingDecline === null
            && !$this->quote->isVirtual()
            && !$this->quote->getShippingAddress()->getShippingMethod()
        ) {
            $shippingDecline = ValidateOrderResponseInterface::REASON_SHIPPING;
            $this->logValidateError('create', 'not a virtual order, invalid shipping method selected');
        }

        if ($shippingDecline !== null) {
            $this->quote = null;
            $this->validationRequest = null;

            return $container->setDeclineReason($shippingDecline);
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
     * Put the quote on the delivery Qliro validates, which is the one the buyer paid for
     *
     * A late browser update can leave the quote on another method (PLIN-461), and rating again
     * under an address that arrived later can leave it on none (PLIN-376).
     *
     * @return string|null The decline reason, null when the quote may be placed
     */
    private function applySelectedShippingMethod(): ?string
    {
        // A quote that is already an order is left alone, as the stock check does for a resent callback
        if ($this->quote->isVirtual() || !$this->quote->getIsActive()) {
            return null;
        }

        $storeId = $this->quote->getStoreId();

        // Both put one fixed code of their own on the quote, which Qliro's selection is not
        if ($this->config->isUnifaunEnabled($storeId) || $this->config->isIngridEnabled($storeId)) {
            return null;
        }

        $code = (string)$this->validationRequest->getSelectedShippingMethod();
        $shippingAddress = $this->quote->getShippingAddress();
        $quoteMethod = (string)$shippingAddress->getShippingMethod();
        // A quote on a method of its own is accepted on it as before, one on none has nothing to place
        $unplaceable = $quoteMethod === '' ? ValidateOrderResponseInterface::REASON_SHIPPING : null;

        if ($code === '' || $code === $quoteMethod) {
            return $code === '' ? $unplaceable : null;
        }

        // The callback runs in the default store view, and totals are collected in the current one's currency
        $quoteStoreId = (int)$storeId;
        $isEmulated = false;

        try {
            if ($quoteStoreId > 0 && $quoteStoreId !== (int)$this->getStoreManager()->getStore()->getId()) {
                $this->getStoreEmulation()->startEnvironmentEmulation($quoteStoreId, Area::AREA_FRONTEND, true);
                // Magento refuses a nested emulation silently, so only a start that took owns a stop
                $isEmulated = $quoteStoreId === (int)$this->getStoreManager()->getStore()->getId();
            }
        } catch (\Throwable $exception) {
            $this->logValidateError(
                'applySelectedShippingMethod',
                'could not enter the quote store view: ' . $exception->getMessage(),
                ['quote_method' => $quoteMethod, 'qliro_method' => $code]
            );

            return $unplaceable;
        }

        try {
            if (!$shippingAddress->getShippingRateByCode($code)) {
                // The saved rates can predate the address the carriers answer for now (PLIN-376)
                $shippingAddress->setCollectShippingRates(true);
                $shippingAddress->collectShippingRates();
            }

            if (!$shippingAddress->getShippingRateByCode($code)) {
                $offered = [];

                foreach ($shippingAddress->getAllShippingRates() as $rate) {
                    $offered[] = $rate->getCode();
                }

                // What the carriers answered and which parts of the address they had, not its values
                $this->logValidateError(
                    'applySelectedShippingMethod',
                    'the method Qliro selected is not among the rates',
                    [
                        'quote_method' => $quoteMethod,
                        'qliro_method' => $code,
                        'offered_methods' => $offered,
                        'address_has' => [
                            'street' => (bool)$shippingAddress->getStreetLine(1),
                            'city' => (bool)$shippingAddress->getCity(),
                            'postcode' => (bool)$shippingAddress->getPostcode(),
                            'country' => (bool)$shippingAddress->getCountryId(),
                        ],
                    ]
                );

                return $unplaceable;
            }

            // A price disagreement, like one on a product line, not a delivery the address cannot get
            return $this->switchToSelectedMethod($code, $quoteMethod)
                ? null
                : ValidateOrderResponseInterface::REASON_OTHER;
        } catch (\Exception $exception) {
            $this->logValidateError(
                'applySelectedShippingMethod',
                'could not apply the method Qliro selected: ' . $exception->getMessage(),
                ['quote_method' => $quoteMethod, 'qliro_method' => $code]
            );

            return $unplaceable;
        } finally {
            if ($isEmulated) {
                $this->getStoreEmulation()->stopEnvironmentEmulation();
            }
        }
    }

    /**
     * Move the quote to the method Qliro selected, at Qliro's price, and save it
     *
     * @param string $code
     * @param string $quoteMethod
     * @return bool False when the store prices that method differently than Qliro
     */
    private function switchToSelectedMethod(string $code, string $quoteMethod): bool
    {
        $shippingAddress = $this->quote->getShippingAddress();
        $this->putQuoteOnMethod($code);

        $this->quote->setTotalsCollectedFlag(false);
        $this->quote->collectTotals();

        $quotePrice = (float)$shippingAddress->getShippingInclTax();
        $qliroPrice = $this->getQliroShippingPrice();

        // One öre of slack, the two amounts are rounded by different code
        if (\abs(\round($quotePrice, 2) - \round($qliroPrice, 2)) > 0.011) {
            $this->logValidateError(
                'applySelectedShippingMethod',
                'the store prices the method Qliro selected differently',
                ['qliro_method' => $code, 'quote_price' => $quotePrice, 'qliro_price' => $qliroPrice]
            );
            // Nothing is saved on a decline, and the quote in memory is not left on the refused method
            $this->putQuoteOnMethod($quoteMethod === '' ? null : $quoteMethod);

            return false;
        }

        // Saved, so placing the order starts from it even when the Qliro order has no shipping line.
        // A failed save is no reason to refuse the payment, placing applies the Qliro line again
        try {
            $this->getQuoteRepository()->save($this->quote);
        } catch (\Exception $exception) {
            $this->logValidateError(
                'applySelectedShippingMethod',
                'could not save the quote on the method Qliro selected: ' . $exception->getMessage(),
                ['qliro_method' => $code]
            );
        }

        $this->logManager->debug(
            'CALLBACK:VALIDATE: applied the method Qliro selected',
            ['extra' => ['quote_id' => $this->quote->getId(), 'quote_method' => $quoteMethod, 'qliro_method' => $code]]
        );

        return true;
    }

    /**
     * Put the address and the shipping assignment on a method, the repository saves the assignment's
     *
     * @param string|null $code
     */
    private function putQuoteOnMethod(?string $code): void
    {
        $this->quote->getShippingAddress()->setShippingMethod($code);

        $assignments = $this->quote->getExtensionAttributes()
            ? $this->quote->getExtensionAttributes()->getShippingAssignments()
            : null;

        foreach (is_array($assignments) ? $assignments : [] as $assignment) {
            $assignment->getShipping()->setMethod($code);
        }
    }

    /**
     * What Qliro charges for delivery on this order, VAT included
     *
     * @return float Zero when the order carries no shipping line, which is what Qliro charges then
     */
    private function getQliroShippingPrice(): float
    {
        $total = 0.0;

        foreach ($this->validationRequest->getOrderItems() ?? [] as $item) {
            if ($item->getType() === QliroOrderItemInterface::TYPE_SHIPPING) {
                $total += (float)$item->getPricePerItemIncVat() * (float)$item->getQuantity();
            }
        }

        return $total;
    }

    /**
     * These three are resolved on first use, Magento passes null for an optional argument instead of injecting it
     *
     * @return CartRepositoryInterface
     */
    private function getQuoteRepository(): CartRepositoryInterface
    {
        return $this->quoteRepository
            ??= ObjectManager::getInstance()->get(CartRepositoryInterface::class);
    }

    private function getStoreManager(): StoreManagerInterface
    {
        return $this->storeManager ??= ObjectManager::getInstance()->get(StoreManagerInterface::class);
    }

    private function getStoreEmulation(): Emulation
    {
        return $this->storeEmulation ??= ObjectManager::getInstance()->get(Emulation::class);
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
