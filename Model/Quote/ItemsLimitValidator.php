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
     */
    public function __construct(
        private readonly LogManager $logManager
    ) {
    }

    /**
     * Validate that the quote does not exceed Qliro's per-order item limit.
     *
     * On violation a structured WARNING is logged (quote_id, item count, store_id, limit),
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

        $itemsCount = 0;
        foreach ($quote->getAllVisibleItems() as $item) {
            $itemsCount += (int)$item->getQty();
        }

        if ($itemsCount <= self::MAX_ITEMS) {
            return;
        }

        $this->logManager->warning(
            'Qliro item-limit exceeded — checkout blocked.',
            ['extra' => [
                'quote_id'    => $quote->getId(),
                'store_id'    => $quote->getStoreId(),
                'item_count'  => $itemsCount,
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
