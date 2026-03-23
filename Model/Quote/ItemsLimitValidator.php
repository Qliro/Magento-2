<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;

/**
 * Class responsible for validating the maximum allowed items in a quote
 */
class ItemsLimitValidator
{
    /** @var int */
    private const MAX_ITEMS = 200;

    /**
     * Validate that the quote does not exceed max items
     *
     * @throws LocalizedException
     */
    public function validateQuoteItemsLimit(Quote $quote): void
    {
        if (!$quote->getId()) {
            return;
        }

        $itemsCount = 0;

        foreach ($quote->getAllVisibleItems() as $item) {
            $itemsCount += (int)$item->getQty();
        }

        if ($itemsCount > self::MAX_ITEMS) {
            throw new LocalizedException(
                __(
                    'Qliro supports a maximum of %1 items per order. Please reduce the number of items and try again',
                    self::MAX_ITEMS
                )
            );
        }
    }
}
