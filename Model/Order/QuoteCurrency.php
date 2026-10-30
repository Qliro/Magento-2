<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Order;

use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Quote\Model\Quote;

/**
 * Keep the currency and rate the buyer paid Qliro in while the order is placed from the quote.
 *
 * Qliro's checkout status push has no storefront session, so Magento prices and saves the quote in
 * the store's default display currency at today's rate (Quote::beforeSave, PriceCurrency::convert).
 */
class QuoteCurrency
{
    /**
     * @var CurrencyFactory
     */
    private CurrencyFactory $currencyFactory;

    /**
     * @var HttpContext
     */
    private HttpContext $httpContext;

    /**
     * @param CurrencyFactory $currencyFactory
     * @param HttpContext $httpContext
     */
    public function __construct(CurrencyFactory $currencyFactory, HttpContext $httpContext)
    {
        $this->currencyFactory = $currencyFactory;
        $this->httpContext = $httpContext;
    }

    /**
     * @param Quote $quote
     * @return void
     */
    public function pin(Quote $quote): void
    {
        $code = (string)$quote->getQuoteCurrencyCode();
        $rate = (float)$quote->getBaseToQuoteRate();
        $store = $quote->getStore();
        $base = $store->getBaseCurrency();
        if ($code === '' || $rate <= 0.0 || (string)$quote->getBaseCurrencyCode() !== (string)$base->getCode()) {
            return;
        }

        // The quote keeps the session's rate to 4 decimals: today's full rate if it rounds to that
        if (abs(round((float)$base->getRate($code), 4) - $rate) > 0.00001) {
            $base->setRates([$code => $rate] + (array)$base->getRates());
        }

        $currency = $this->currencyFactory->create()->load($code);
        $store->setData('current_currency', $currency);
        $quote->setForcedCurrency($currency);
        // What getCurrentCurrencyCode() reads first, also on the request's own store
        $this->httpContext->setValue(HttpContext::CONTEXT_CURRENCY, $code, null);
    }
}
