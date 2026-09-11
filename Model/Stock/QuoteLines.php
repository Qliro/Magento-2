<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Stock;

use Magento\Quote\Api\Data\CartInterface;

/**
 * Reads a cart the way Magento reads it when it takes the stock for an order.
 *
 * `Magento\CatalogInventory\Observer\ProductQty` is the model: a line with children in the cart
 * holds no stock of its own, its children are in the same list and hold it, and a child counts for
 * its own quantity times its parent's. The same sku on two lines is one quantity, because that is
 * what the cart asks the stock for.
 */
class QuoteLines
{
    /**
     * Gather what the cart asks the stock for
     *
     * A cart is read through `getAllItems()`, which is what `Magento\Quote\Model\Quote` gives and
     * what every cart in the checkout is.
     *
     * @param CartInterface $quote
     * @return array sku => ['qty' => float, 'type' => string]
     */
    public function fromQuote(CartInterface $quote): array
    {
        $items = $quote->getAllItems();
        $present = [];

        foreach ($items as $item) {
            $present[spl_object_id($item)] = true;
        }

        $lines = [];

        foreach ($items as $item) {
            if ($this->hasChildInCart($item, $present)) {
                continue;
            }

            $sku = (string)$item->getSku();

            if ($sku === '') {
                continue;
            }

            $lines[$sku] = [
                'qty' => ($lines[$sku]['qty'] ?? 0.0) + (float)$item->getTotalQty(),
                'type' => (string)$item->getProductType(),
            ];
        }

        return $lines;
    }

    /**
     * Tell whether the line's stock is carried by a child that is in the cart
     *
     * A child whose product was disabled after it was added is gone from the cart, and then its
     * parent is all that is left of the line.
     *
     * @param mixed $item
     * @param array $present
     * @return bool
     */
    private function hasChildInCart($item, array $present): bool
    {
        foreach ($item->getChildren() as $child) {
            if (isset($present[spl_object_id($child)])) {
                return true;
            }
        }

        return false;
    }
}
