<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Converter;

use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Helper\Data as Helper;

/**
 * Quote from shipping methods container converter class
 */
class QuoteFromShippingMethodsConverter
{
    /**
     * Class constructor
     *
     * @param AddressConverter $addressConverter
     * @param Helper $helper
     */
    public function __construct(
        private readonly AddressConverter $addressConverter,
        private readonly Helper $helper
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
            $shippingAddress->setSameAsBilling($this->helper->doAddressesMatch($shippingAddress, $billingAddress));
        }
    }
}
