<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Plugin\Magento\Quote;

use Magento\Quote\Model\Quote\Item;

/**
 * For configurable products, Magento creates two quote items per single "add to cart":
 *   - the configurable PARENT (visible)
 *   - the simple CHILD variant (hidden, used for stock deduction)
 *
 * Under MSI + reservations, Quote\Item::addQty can be called for both the parent and the
 * child in the same flow, causing the CHILD's qty to drift to 2 even though the customer
 * only added 1 of the configurable. Magento's stock validators then read child.qty = 2,
 * compare against available stock = 1, and throw "Not enough items for sale" — which
 * surfaces in the Qliro flow as "QliroOne Checkout has failed to load."
 *
 * Semantically the truth is unambiguous: a configurable child's qty IS its parent's qty.
 * This plugin makes every read of the child's qty return the parent's qty, neutralising
 * the double-counting wherever stock validation reads from. The child's stored qty is
 * left untouched (so existing persistence behaviour is unchanged); we only correct the
 * value that callers actually see.
 *
 * Scope: ALL quote items in the system. The fix is mathematically correct for
 * configurables and a no-op for everything else (only kicks in when the item has a
 * configurable parent in the same quote).
 */
class ConfigurableChildQtyPlugin
{
    /**
     * @param Item        $subject
     * @param float|int   $result  Original qty returned by Quote\Item::getQty()
     * @return float|int
     */
    public function afterGetQty(Item $subject, mixed $result): mixed
    {
        $parent = $subject->getParentItem();
        if ($parent === null) {
            return $result;
        }
        if ($parent->getProductType() !== 'configurable') {
            return $result;
        }

        $parentQty = $parent->getQty();
        if ($parentQty === null) {
            return $result;
        }

        return $parentQty;
    }
}
