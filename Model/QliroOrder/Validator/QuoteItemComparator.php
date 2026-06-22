<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Validator;

use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Validator\StockAvailabilityChecker;

/**
 * Validates quote items against the Qliro order item list received in the validated callback.
 *
 * Extracted from ValidateOrderBuilder (SRP): item-comparison algorithms do not belong in a builder.
 */
class QuoteItemComparator
{
    public function __construct(
        private readonly StockAvailabilityChecker $stockChecker,
        private readonly LogManager               $logManager
    ) {
    }

    /**
     * Check that all visible quote items are in stock for their requested qty.
     *
     * Stock resolution (MSI vs legacy CatalogInventory) is delegated to
     * {@see StockAvailabilityChecker}, so this class doesn't need to know
     * which inventory backend the store uses.
     *
     * @param Quote $quote
     * @return bool
     */
    public function checkInStock(Quote $quote): bool
    {
        $scopeId = (int) $quote->getStore()->getWebsiteId();

        foreach ($quote->getAllVisibleItems() as $quoteItem) {
            $sku = (string) $quoteItem->getSku();
            $this->logManager->debug('Getting stock for sku: ' . $sku);

            if (!$this->stockChecker->isAvailable($sku, (float) $quoteItem->getQty(), $scopeId)) {
                $this->logManager->debug('Sku is out of stock: ' . $sku);
                $this->logError('checkInStock', 'not enough stock', ['sku' => $sku]);
                return false;
            }
        }

        return true;
    }

    /**
     * Compare quote item DTOs (built from quote) against raw Qliro order items (from callback).
     *
     * @param array[] $quoteItems  Items built from quote by OrderItemsBuilder (raw arrays)
     * @param array[] $qliroItems  Raw items from Qliro callback payload
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

        $groupedQuote = [];
        foreach ($quoteItems as $item) {
            if (in_array($item['Type'] ?? null, $skipTypes)) {
                continue;
            }
            $groupedQuote[$item['MerchantReference'] ?? ''][] = $item;
        }

        $groupedQliro = [];
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
            $groupedQliro[$ref][] = $item;
        }

        if (array_diff_key($groupedQuote, $groupedQliro)
            || array_diff_key($groupedQliro, $groupedQuote)) {
            $this->logError('compare', 'merchant reference set mismatch', [
                'quote_refs' => array_keys($groupedQuote),
                'qliro_refs' => array_keys($groupedQliro),
            ]);
            return false;
        }

        foreach ($groupedQliro as $ref => $qliroLines) {
            $quoteLines = $groupedQuote[$ref];
            if (count($quoteLines) !== count($qliroLines)) {
                $this->logError('compare', 'line count mismatch for reference', [
                    'ref'         => $ref,
                    'quote_count' => count($quoteLines),
                    'qliro_count' => count($qliroLines),
                ]);
                return false;
            }
            // Multiset match: each Qliro line must consume exactly one matching quote line.
            $remaining = $quoteLines;
            foreach ($qliroLines as $qliroLine) {
                $matchedIdx = null;
                foreach ($remaining as $idx => $candidate) {
                    if ($this->itemsMatch($candidate, $qliroLine)) {
                        $matchedIdx = $idx;
                        break;
                    }
                }
                if ($matchedIdx === null) {
                    $this->logError('compare', 'no matching quote line for Qliro line', [
                        'ref'        => $ref,
                        'qliro_line' => $qliroLine,
                    ]);
                    return false;
                }
                unset($remaining[$matchedIdx]);
            }
        }

        return true;
    }

    /**
     * Compare a single quote item against a single Qliro item.
     *
     * Uses a 0.01 currency tolerance so that small rounding differences from VAT
     * calculations do not cause spurious validation declines. Returns bool without
     * logging — the caller logs context when the whole comparison fails.
     */
    private function itemsMatch(array $quoteItem, array $qliroItem): bool
    {
        $epsilon = 0.01;

        $exVatQuote = (float) ($quoteItem['PricePerItemExVat'] ?? 0);
        $exVatQliro = (float) ($qliroItem['PricePerItemExVat'] ?? 0);
        if (abs($exVatQuote - $exVatQliro) > $epsilon) {
            return false;
        }

        $incVatQuote = (float) ($quoteItem['PricePerItemIncVat'] ?? 0);
        $incVatQliro = (float) ($qliroItem['PricePerItemIncVat'] ?? 0);
        if (abs($incVatQuote - $incVatQliro) > $epsilon) {
            return false;
        }

        if ((float) ($quoteItem['Quantity'] ?? 0) !== (float) ($qliroItem['Quantity'] ?? 0)) {
            return false;
        }

        if (($quoteItem['Type'] ?? null) !== ($qliroItem['Type'] ?? null)) {
            return false;
        }

        return true;
    }

    /**
     * @param string $function
     * @param string $reason
     * @param array $details
     * @return void
     */
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
