<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder;

use Magento\Quote\Model\Quote;

/**
 * What the order lines the module sends for a cart have to add up to
 *
 * The delivery and the invoice fee are lines Qliro puts on the order itself, from what the
 * checkout offered it, so the lines the module sends carry the rest of the store's total. This is
 * the figure the checkout hands the browser to compare the Qliro order against, and the figure a
 * rounding adjustment is measured from, and the two have to be the same one.
 */
class LinesTotal
{
    /**
     * @param Quote $quote
     * @return float
     */
    public function ofQuote(Quote $quote): float
    {
        if ($quote->isVirtual()) {
            $address = $quote->getBillingAddress();
            $shippingCost = 0.0;
        } else {
            $address = $quote->getShippingAddress();
            $shippingCost = (float)$address->getShippingInclTax();
        }

        return (float)$quote->getGrandTotal() - (float)$address->getQlirooneFee() - $shippingCost;
    }
}
