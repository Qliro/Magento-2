<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Order;

use Magento\Sales\Model\Order;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * Syncs confirmed order-item quantities from a Qliro order onto a placed Magento order.
 *
 * Extracted from PlaceOrder::syncOrderItems() (SRP).
 */
class OrderItemsSyncer
{
    /**
     * Class constructor
     *
     * @param LogManager $logManager
     */
    public function __construct(
        private readonly LogManager $logManager
    ) {
    }

    /**
     * Adjust item quantities and recalculate order totals to match what Qliro confirmed.
     *
     * Qliro may have reduced a line item's quantity during its validation step (e.g. stock
     * depletion between page-load and payment confirmation). The quote was snapshotted at
     * page-load time, so the order was placed with the original quantities. This method
     * adjusts qty_ordered and recalculates per-item row totals proportionally so the order
     * reflects exactly what Qliro confirmed.
     *
     * Product items are matched via Metadata['quoteItems'] which contains "quoteItemId:sku"
     * entries — the same reference format used by OrderSourceProvider.
     *
     * @param Order $order
     * @param array $qliroOrder
     */
    public function sync(Order $order, array $qliroOrder): void
    {
        $changed = false;

        foreach ($qliroOrder['OrderItems'] ?? [] as $qliroItem) {
            if (($qliroItem['Type'] ?? null) !== 'Product') {
                continue;
            }

            $qliroQty = (float) ($qliroItem['Quantity'] ?? 0);
            if ($qliroQty <= 0) {
                continue;
            }

            // Metadata['quoteItems'] = ['quoteItemId:sku' => 'quoteItemId:sku', ...]
            $quoteItemRef = null;
            foreach ($qliroItem['Metadata']['quoteItems'] ?? [] as $ref) {
                $quoteItemRef = $ref;
                break;
            }

            if ($quoteItemRef === null) {
                continue;
            }

            // Extract quoteItemId from "quoteItemId:sku"
            $quoteItemId = str_contains($quoteItemRef, ':') ? (int) explode(':', $quoteItemRef)[0] : null;

            if (!$quoteItemId) {
                continue;
            }

            $orderItem = $order->getItemByQuoteItemId($quoteItemId);
            if (!$orderItem) {
                continue;
            }

            $originalQty = (float) $orderItem->getQtyOrdered();
            if ($originalQty == $qliroQty) {
                continue; // no change
            }

            // Scale all per-row monetary values by the qty ratio
            $ratio = $qliroQty / $originalQty;

            $orderItem->setQtyOrdered($qliroQty);
            $orderItem->setRowTotal(           round($orderItem->getRowTotal()            * $ratio, 4));
            $orderItem->setBaseRowTotal(       round($orderItem->getBaseRowTotal()        * $ratio, 4));
            $orderItem->setRowTotalInclTax(    round($orderItem->getRowTotalInclTax()     * $ratio, 4));
            $orderItem->setBaseRowTotalInclTax(round($orderItem->getBaseRowTotalInclTax() * $ratio, 4));
            $orderItem->setTaxAmount(          round($orderItem->getTaxAmount()           * $ratio, 4));
            $orderItem->setBaseTaxAmount(      round($orderItem->getBaseTaxAmount()       * $ratio, 4));
            $orderItem->setDiscountAmount(     round($orderItem->getDiscountAmount()      * $ratio, 4));
            $orderItem->setBaseDiscountAmount( round($orderItem->getBaseDiscountAmount()  * $ratio, 4));

            $changed = true;

            $this->logManager->debug('OrderItemsSyncer: adjusted qty', [
                'extra' => [
                    'order_id'      => $order->getId(),
                    'quote_item_id' => $quoteItemId,
                    'sku'           => $orderItem->getSku(),
                    'qty_before'    => $originalQty,
                    'qty_after'     => $qliroQty,
                ],
            ]);
        }

        if (!$changed) {
            return;
        }

        // Recalculate order-level totals from the updated line items
        $subtotal            = 0.0;
        $baseSubtotal        = 0.0;
        $subtotalInclTax     = 0.0;
        $baseSubtotalInclTax = 0.0;
        $taxAmount           = 0.0;
        $baseTaxAmount       = 0.0;
        $discountAmount      = 0.0;
        $baseDiscountAmount  = 0.0;

        foreach ($order->getAllItems() as $item) {
            if ($item->getParentItemId()) {
                continue; // child items (e.g. configurable simple) must not be double-counted
            }
            $subtotal            += (float) $item->getRowTotal();
            $baseSubtotal        += (float) $item->getBaseRowTotal();
            $subtotalInclTax     += (float) $item->getRowTotalInclTax();
            $baseSubtotalInclTax += (float) $item->getBaseRowTotalInclTax();
            $taxAmount           += (float) $item->getTaxAmount();
            $baseTaxAmount       += (float) $item->getBaseTaxAmount();
            $discountAmount      += (float) $item->getDiscountAmount();
            $baseDiscountAmount  += (float) $item->getBaseDiscountAmount();
        }

        $order->setSubtotal(            round($subtotal, 4));
        $order->setBaseSubtotal(        round($baseSubtotal, 4));
        $order->setSubtotalInclTax(     round($subtotalInclTax, 4));
        $order->setBaseSubtotalInclTax( round($baseSubtotalInclTax, 4));
        $order->setTaxAmount(           round($taxAmount + (float) $order->getShippingTaxAmount(), 4));
        $order->setBaseTaxAmount(       round($baseTaxAmount + (float) $order->getBaseShippingTaxAmount(), 4));
        $order->setDiscountAmount(     -round($discountAmount, 4));
        $order->setBaseDiscountAmount( -round($baseDiscountAmount, 4));

        $grandTotal = $subtotalInclTax + (float) $order->getShippingInclTax() - $discountAmount;

        $order->setGrandTotal(    round($grandTotal, 4));
        $order->setBaseGrandTotal(round($grandTotal, 4));
    }
}
