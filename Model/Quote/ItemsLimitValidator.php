<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
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
     * @param LogManager $logManager
     * @param OrderItemsBuilder $orderItemsBuilder
     */
    public function __construct(
        private readonly LogManager $logManager,
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
     * configurable collapses to its child line. Summing getAllVisibleItems() was wrong twice
     * over, it counted quantities and skipped bundle/configurable children.
     *
     * On violation a structured WARNING is logged (quote_id, line count, store_id, limit),
     * and a LocalizedException is thrown — the caller decides whether to surface it to
     * the user (storefront), reject submission (sales_model_service_quote_submit_before),
     * or decline the validated callback.
     *
     * @throws LocalizedException
     */
    public function validateQuoteItemsLimit(Quote $quote): void
    {
        if (!$quote->getId()) {
            return;
        }

        $lineCount = count($this->orderItemsBuilder->setQuote($quote)->create());

        if ($lineCount <= self::MAX_ITEMS) {
            return;
        }

        $this->logManager->warning(
            'Qliro item-limit exceeded — checkout blocked.',
            ['extra' => [
                'quote_id'    => $quote->getId(),
                'store_id'    => $quote->getStoreId(),
                'line_count'  => $lineCount,
                'limit'       => self::MAX_ITEMS,
                'increment_id' => $quote->getReservedOrderId(),
            ]]
        );

        throw new LocalizedException(
            __(
                'Qliro supports a maximum of %1 items per order. Please reduce the number of items and try again',
                self::MAX_ITEMS
            )
        );
    }
}
