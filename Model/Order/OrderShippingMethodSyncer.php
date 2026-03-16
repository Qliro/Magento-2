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
 * Syncs the confirmed shipping method and amount from a Qliro order onto a placed Magento order.
 *
 * Extracted from PlaceOrder::syncShippingMethod() (SRP).
 */
class OrderShippingMethodSyncer
{
    public function __construct(
        private readonly LogManager $logManager
    ) {
    }

    /**
     * Apply the confirmed shipping method code and price from the Qliro order to the Magento order.
     *
     * The customer may have changed shipping method inside the Qliro iframe after the
     * order was placed in STATE_PENDING_PAYMENT. The Qliro confirmed order carries
     * SelectedShippingMethod['MerchantReference'] which equals the Magento shipping
     * method code (e.g. "flatrate_flatrate") and PriceIncVat for the confirmed amount.
     *
     * @param Order $order
     * @param array $qliroOrder
     */
    public function sync(Order $order, array $qliroOrder): void
    {
        $selected = $qliroOrder['SelectedShippingMethod'] ?? null;
        if (empty($selected)) {
            return;
        }

        $confirmedCode     = $selected['MerchantReference'] ?? null;
        $confirmedPriceInc = isset($selected['PriceIncVat']) ? (float) $selected['PriceIncVat'] : null;
        $confirmedPriceEx  = isset($selected['PriceExVat'])  ? (float) $selected['PriceExVat']  : null;

        if (!$confirmedCode) {
            return;
        }

        $codeChanged  = $order->getShippingMethod()  !== $confirmedCode;
        $priceChanged = $confirmedPriceInc !== null
            && round((float) $order->getShippingInclTax(), 2) !== round($confirmedPriceInc, 2);

        if (!$codeChanged && !$priceChanged) {
            return;
        }

        if ($codeChanged) {
            $order->setShippingMethod($confirmedCode);
            $this->logManager->debug('OrderShippingMethodSyncer: code changed', [
                'extra' => [
                    'order_id' => $order->getId(),
                    'from'     => $order->getShippingMethod(),
                    'to'       => $confirmedCode,
                ],
            ]);
        }

        if ($confirmedPriceInc !== null) {
            $taxAmount = $confirmedPriceEx !== null
                ? round($confirmedPriceInc - $confirmedPriceEx, 4)
                : (float) $order->getShippingTaxAmount();

            $order->setShippingAmount(       round($confirmedPriceEx ?? $confirmedPriceInc, 4));
            $order->setBaseShippingAmount(   round($confirmedPriceEx ?? $confirmedPriceInc, 4));
            $order->setShippingInclTax(      round($confirmedPriceInc, 4));
            $order->setBaseShippingInclTax(  round($confirmedPriceInc, 4));
            $order->setShippingTaxAmount(    round($taxAmount, 4));
            $order->setBaseShippingTaxAmount(round($taxAmount, 4));

            // Recalculate grand total with updated shipping
            $grandTotal = (float) $order->getSubtotalInclTax()
                + $confirmedPriceInc
                + (float) $order->getDiscountAmount(); // discount is stored as negative

            $order->setGrandTotal(    round($grandTotal, 4));
            $order->setBaseGrandTotal(round($grandTotal, 4));

            // Recalculate total tax including updated shipping tax
            $itemsTax = 0.0;
            foreach ($order->getAllItems() as $item) {
                if (!$item->getParentItemId()) {
                    $itemsTax += (float) $item->getTaxAmount();
                }
            }
            $order->setTaxAmount(    round($itemsTax + $taxAmount, 4));
            $order->setBaseTaxAmount(round($itemsTax + $taxAmount, 4));
        }
    }
}
