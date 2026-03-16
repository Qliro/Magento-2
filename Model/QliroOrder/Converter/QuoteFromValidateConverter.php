<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Converter;

use Magento\Quote\Model\Quote;

/**
 * Quote from validate order container converter class
 */
class QuoteFromValidateConverter
{
    /**
     * Class constructor
     *
     * @param AddressConverter $addressConverter
     */
    public function __construct(
        private readonly AddressConverter $addressConverter
    ) {
    }

    /**
     * Convert validate order request into quote
     *
     * @param \Qliro\QliroOne\Api\Data\ValidateOrderNotificationInterface $container
     * @param \Magento\Quote\Model\Quote $quote
     */
    public function convert(array $container, Quote $quote)
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
    }
}
