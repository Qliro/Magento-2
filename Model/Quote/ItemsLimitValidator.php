<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Model\QliroOrder\Builder\OrderItemsBuilder;

/**
 * Class responsible for validating the maximum allowed items in a quote
 */
class ItemsLimitValidator
{
    /** @var int */
    public const MAX_ITEMS = 200;

    /**
     * Class constructor
     *
     * @param OrderItemsBuilder $orderItemsBuilder
     */
    public function __construct(
        private readonly OrderItemsBuilder $orderItemsBuilder
    ) {
    }

    /**
     * Validate that the quote does not exceed Qliro's per-order item limit.
     *
     * The limit is on the number of order LINES sent to Qliro, not the summed unit
     * quantity, so one product at qty 300 counts as a single line. The count is taken from
     * the exact array OrderItemsBuilder produces for the create/update order request, so it
     * matches the real payload: a bundle expands to its parent plus one line per child, a
     * configurable collapses to its child line, and a cart discount is its own line. Summing
     * getAllVisibleItems() was wrong twice over, it counted quantities and skipped
     * bundle/configurable children.
     *
     * @throws LocalizedException
     */
    public function validateQuoteItemsLimit(Quote $quote): void
    {
        if (!$quote->getId()) {
            return;
        }

        $lineCount = count($this->orderItemsBuilder->setQuote($quote)->create());

        if ($lineCount > self::MAX_ITEMS) {
            throw new LocalizedException(
                __(
                    'Qliro supports a maximum of %1 items per order. Please reduce the number of items and try again',
                    self::MAX_ITEMS
                )
            );
        }
    }
}
