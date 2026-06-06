<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Converter;

use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * Quote from validate order container converter class
 */
class QuoteFromValidateConverter
{
    /**
     * Class constructor
     *
     * @param AddressConverter        $addressConverter
     * @param CartRepositoryInterface $quoteRepository
     * @param LogManager              $logManager
     */
    public function __construct(
        private readonly AddressConverter        $addressConverter,
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly LogManager              $logManager
    ) {
    }

    /**
     * Convert validate order request into quote
     *
     * @param array $container  Raw Qliro validate-callback payload
     * @param Quote $quote
     */
    public function convert(array $container, Quote $quote): void
    {
        $billingAddress = $quote->getBillingAddress();
        $this->addressConverter->convert(
            $container['BillingAddress'] ?? [],
            $container['Customer'] ?? [],
            $billingAddress
        );

        if (!$quote->isVirtual()) {
            $shippingAddress = $quote->getShippingAddress();
            $shippingAddress->setShippingMethod($container['SelectedShippingMethod'] ?? null);
            $this->addressConverter->convert(
                $container['ShippingAddress'] ?? [],
                $container['Customer'] ?? [],
                $shippingAddress
            );
        }

        // Persist the quote so the customer-confirmed shipping_method and address data
        // reach the DB before order placement reads them.
        //
        // Previously this converter only mutated the in-memory object; if the iframe never
        // fired onShippingMethodChanged (e.g. the customer's selection didn't produce a
        // change event in the widget), the validate callback was the only path carrying
        // the chosen method — and that path discarded it. Result: QuoteValidator threw
        // "The shipping method is missing" at submitQuote() time.
        try {
            $this->quoteRepository->save($quote);
        } catch (\Exception $e) {
            // Non-fatal: persistence failure here doesn't break the validate response,
            // but log it so we can see when the safety-net save itself ran into trouble.
            $this->logManager->warning(
                'QuoteFromValidateConverter: failed to persist quote after applying validate payload.',
                ['extra' => [
                    'quote_id' => $quote->getId(),
                    'error'    => $e->getMessage(),
                ]]
            );
        }
    }
}
