<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Observer\Quote\Model;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Model\Quote\ItemsLimitValidator;

/**
 * Handles the quote items limit during event observation
 */
class QuoteManagement implements ObserverInterface
{
    /**
     * Class constructor
     *
     * @param ItemsLimitValidator $itemLimitValidator
     */
    public function __construct(
        private readonly ItemsLimitValidator $itemLimitValidator
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function execute(Observer $observer): void
    {
        /** @var Quote|null $quote */
        $quote = $observer->getEvent()->getQuote();
        if (!$quote) {
            return;
        }

        $paymentMethod = $quote->getPayment()->getMethod();
        if ($paymentMethod == 'qliroone') {
            $this->itemLimitValidator->validateQuoteItemsLimit($quote);
        }
    }
}
