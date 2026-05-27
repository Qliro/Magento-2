<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Converter;

use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Service\Quote\AddressComparator;

/**
 * Quote from shipping methods container converter class
 */
class QuoteFromShippingMethodsConverter
{
    /**
     * Class constructor
     *
     * @param AddressConverter $addressConverter
     * @param AddressComparator $addressComparator
     */
    public function __construct(
        private readonly AddressConverter  $addressConverter,
        private readonly AddressComparator $addressComparator
    ) {
    }

    /**
     * Convert update shipping methods request into quote
     *
     * @param \Qliro\QliroOne\Api\Data\UpdateShippingMethodsNotificationInterface $container
     * @param \Magento\Quote\Model\Quote $quote
     */
    public function convert(array $container, Quote $quote): void
    {
        $qliroAddress  = $container['ShippingAddress'] ?? [];
        $qliroCustomer = $container['Customer'] ?? [];
        $countryCode   = $container['CountryCode'] ?? null;

        $billingAddress = $quote->getBillingAddress();
        $this->addressConverter->convert($qliroAddress, $qliroCustomer, $billingAddress, $countryCode);

        if (!$quote->isVirtual()) {
            $shippingAddress = $quote->getShippingAddress();
            $this->addressConverter->convert($qliroAddress, $qliroCustomer, $shippingAddress, $countryCode);
            $shippingAddress->setSameAsBilling($this->addressComparator->match($shippingAddress, $billingAddress));
        }
    }
}
