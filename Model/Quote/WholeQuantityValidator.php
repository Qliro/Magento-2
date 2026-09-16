<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
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
     * Refuse a cart holding a fractional quantity before it becomes an order
     *
     * A store selling by weight or length, or one using `qty_increments`, can hold half a metre
     * of cable in a cart. Qliro carries a whole quantity only, so such a cart cannot be paid for
     * with this method, and the buyer has to hear that at the cart rather than halfway through a
     * checkout that then fails on a totals mismatch. Every cart line is asked, the children of a
     * bundle included, because each one is a line of its own in the payload.
     *
     * @param Quote $quote
     * @return void
     * @throws LocalizedException
     */
    public function validateWholeQuantities(Quote $quote): void
    {
        if (!$quote->getId()) {
            return;
        }

        $fractional = [];

        foreach ($quote->getAllItems() as $item) {
            if ($this->lineQuantity->isWhole((float)$item->getQty())) {
                continue;
            }

            $fractional[] = (string)$item->getSku();
        }

        if (!$fractional) {
            return;
        }

        throw new LocalizedException(
            __(
                'Qliro cannot carry a part of an item. Change %1 to a whole quantity, or pay with '
                . 'another method.',
                implode(', ', array_unique($fractional))
            )
        );
    }
}
