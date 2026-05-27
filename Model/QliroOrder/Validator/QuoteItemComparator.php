<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Validator;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * Validates quote items against the Qliro order item list received in the validate callback.
 *
 * Extracted from ValidateOrderBuilder (SRP): item-comparison algorithms do not belong in a builder.
 */
class QuoteItemComparator
{
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly LogManager $logManager
    ) {
    }

    /**
     * Check that all visible quote items are in stock.
     *
     * @param Quote $quote
     * @return bool
     */
    public function checkInStock(Quote $quote): bool
    {
        foreach ($quote->getAllVisibleItems() as $quoteItem) {
            $this->logManager->debug('Getting stock for product id: ' . $quoteItem->getProduct()->getId());
            $stockItem = $this->stockRegistry->getStockItem(
                $quoteItem->getProduct()->getId(),
                $quoteItem->getProduct()->getStore()->getWebsiteId()
            );

            if (!$stockItem->getIsInStock()) {
                $this->logManager->debug('Product id is out of stock: ' . $quoteItem->getProduct()->getId());
                $this->logError('checkInStock', 'not enough stock', ['sku' => $quoteItem->getSku()]);
                return false;
            }
        }

        return true;
    }

    /**
     * Compare quote item DTOs (built from quote) against raw Qliro order items (from callback).
     *
     * @param QliroOrderItemInterface[] $quoteItems  Items built from quote by OrderItemsBuilder
     * @param array[]                   $qliroItems   Raw items from Qliro callback payload
     * @return bool
     */
    public function compare(array $quoteItems, array $qliroItems): bool
    {
        $skipTypes = [QliroOrderItemInterface::TYPE_SHIPPING, QliroOrderItemInterface::TYPE_FEE];

        if (!$quoteItems) {
            $this->logError('compare', 'no Cart Items');
            return false;
        }
        if (!$qliroItems) {
            $this->logError('compare', 'no Qliro Items');
            return false;
        }

        // Index quote items by merchant reference
        $hashedQuoteItems = [];
        foreach ($quoteItems as $item) {
            if (!in_array($item->getType(), $skipTypes)) {
                $hashedQuoteItems[$item->getMerchantReference()] = $item;
            }
        }

        // Index Qliro items by merchant reference; apply abs() to discount amounts
        $hashedQliroItems = [];
        foreach ($qliroItems as $item) {
            $type = $item['Type'] ?? '';
            if (in_array($type, $skipTypes)) {
                continue;
            }
            if ($type === QliroOrderItemInterface::TYPE_DISCOUNT) {
                $item['PricePerItemExVat']  = abs($item['PricePerItemExVat']  ?? 0);
                $item['PricePerItemIncVat'] = abs($item['PricePerItemIncVat'] ?? 0);
            }
            $ref = $item['MerchantReference'] ?? '';
            $hashedQliroItems[$ref] = $item;

            if (!isset($hashedQuoteItems[$ref])) {
                $this->logError('compare', 'hashedQuoteItems failed');
                return false;
            }
            if (!$this->compareItems($hashedQuoteItems[$ref], $hashedQliroItems[$ref])) {
                return false;
            }
        }

        foreach ($quoteItems as $quoteItem) {
            if (in_array($quoteItem->getType(), $skipTypes)) {
                continue;
            }
            $ref = $quoteItem->getMerchantReference();
            if (!isset($hashedQliroItems[$ref])) {
                $this->logError('compare', '$hashedQliroItems failed');
                return false;
            }
            if (!$this->compareItems($hashedQuoteItems[$ref], $hashedQliroItems[$ref])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compare a quote item DTO against a raw Qliro order item array.
     *
     * @param QliroOrderItemInterface $quoteItem
     * @param array                   $qliroItem
     */
    private function compareItems(QliroOrderItemInterface $quoteItem, array $qliroItem): bool
    {
        if ($quoteItem->getPricePerItemExVat() != ($qliroItem['PricePerItemExVat'] ?? null)) {
            $this->logError('compareItems', 'pricePerItemExVat different', [
                'quote' => $quoteItem->getPricePerItemExVat(),
                'qliro' => $qliroItem['PricePerItemExVat'] ?? null,
            ]);
            return false;
        }
        if ($quoteItem->getPricePerItemIncVat() != ($qliroItem['PricePerItemIncVat'] ?? null)) {
            $this->logError('compareItems', 'pricePerItemIncVat different', [
                'quote' => $quoteItem->getPricePerItemIncVat(),
                'qliro' => $qliroItem['PricePerItemIncVat'] ?? null,
            ]);
            return false;
        }
        if ($quoteItem->getQuantity() != ($qliroItem['Quantity'] ?? null)) {
            $this->logError('compareItems', 'quantity different', [
                'quote' => $quoteItem->getQuantity(),
                'qliro' => $qliroItem['Quantity'] ?? null,
            ]);
            return false;
        }
        if ($quoteItem->getType() != ($qliroItem['Type'] ?? null)) {
            $this->logError('compareItems', 'type different', [
                'quote' => $quoteItem->getType(),
                'qliro' => $qliroItem['Type'] ?? null,
            ]);
            return false;
        }

        return true;
    }

    private function logError(string $function, string $reason, array $details = []): void
    {
        $this->logManager->debug('CALLBACK:VALIDATE', [
            'extra' => [
                'function' => $function,
                'reason'   => $reason,
                'details'  => $details,
            ],
        ]);
    }
}
