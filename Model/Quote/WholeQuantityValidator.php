<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Quote;

use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Model\Exception\UnsupportedQuoteException;
use Qliro\QliroOne\Model\QliroOrder\LineQuantity;

/**
 * Class responsible for refusing a cart Qliro cannot carry the quantity of
 */
class WholeQuantityValidator
{
    /**
     * Class constructor
     *
     * @param LineQuantity $lineQuantity
     */
    public function __construct(
        private readonly LineQuantity $lineQuantity
    ) {
    }

    /**
     * Refuse a cart holding a fractional quantity before a Qliro order is created for it
     *
     * A store selling by weight or length, or one using `qty_increments`, can hold half a metre
     * of cable in a cart. Qliro carries a whole quantity only, so such a cart cannot be paid for
     * with this method, and the buyer has to hear that instead of a checkout that fails on a
     * totals mismatch. Every cart line is asked, the children of a bundle included, because each
     * one is a line of its own in the payload.
     *
     * @param Quote $quote
     * @return void
     * @throws UnsupportedQuoteException
     */
    public function validateWholeQuantities(Quote $quote): void
    {
        $fractional = $this->fractionalLines($quote);

        if (!$fractional) {
            return;
        }

        throw new UnsupportedQuoteException(
            __(
                'Qliro cannot carry a part of an item. Change %1 to a whole quantity, or pay with '
                . 'another method.',
                implode(', ', array_unique(array_column($fractional, 'sku')))
            )
        );
    }

    /**
     * Every cart line Qliro cannot carry the quantity of, in cart order
     *
     * The refusal above and the decline the validate callback answers with read the same cart the
     * same way, one naming every line for the buyer and the other logging the first for the
     * merchant.
     *
     * @param Quote $quote
     * @return array<int, array{sku: string, qty: float}>
     */
    public function fractionalLines(Quote $quote): array
    {
        if (!$quote->getId()) {
            return [];
        }

        $fractional = [];

        foreach ($quote->getAllItems() as $item) {
            $quantity = (float)$item->getQty();

            if ($this->lineQuantity->isWhole($quantity)) {
                continue;
            }

            $fractional[] = ['sku' => (string)$item->getSku(), 'qty' => $quantity];
        }

        return $fractional;
    }
}
