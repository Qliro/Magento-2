<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Carrier;

use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\Method;

/**
 * Carrier and method titles from the shipping description left on the cart, "<carrier> - <method>".
 * The host carrier holds $quoteId and $quoteRepository
 */
trait TitlesFromDescription
{
    /**
     * The quote of the first request item, its id kept for the shipping price
     *
     * @param RateRequest $request
     * @return CartInterface|null
     */
    private function resolveQuote(RateRequest $request): ?CartInterface
    {
        $items = $request->getAllItems();
        $firstItem = is_array($items) ? reset($items) : false;
        $quote = $firstItem ? $firstItem->getQuote() : null;
        $this->quoteId = $firstItem ? ($firstItem->getQuoteId() ?: $quote?->getId()) : null;

        if (!$quote && $this->quoteId) {
            try {
                $quote = $this->quoteRepository->get((int)$this->quoteId);
            } catch (\Exception $exception) {
                $quote = null;
            }
        }

        return $quote ?: null;
    }

    /**
     * Split the description where the provider and the separator stand
     *
     * @param CartInterface|null $quote
     * @param Method $method
     * @param string $provider
     * @return void
     */
    private function applyTitlesFromDescription(?CartInterface $quote, Method $method, string $provider): void
    {
        $description = $quote ? (string)$quote->getShippingAddress()->getShippingDescription() : '';
        $position = strpos($description, $provider . ' - ');

        if ($position === false) {
            return;
        }

        $method->setCarrierTitle(substr($description, 0, $position + strlen($provider)));
        $methodTitle = substr($description, $position + strlen($provider . ' - '));

        if ($methodTitle !== '') {
            $method->setMethodTitle($methodTitle);
        }
    }
}
