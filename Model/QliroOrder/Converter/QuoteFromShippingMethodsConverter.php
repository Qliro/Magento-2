<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Converter;

use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\Data\UpdateShippingMethodsNotificationInterface;
use Qliro\QliroOne\Helper\Data as Helper;

/**
 * Quote from shipping methods container converter class
 */
class QuoteFromShippingMethodsConverter
{
    /**
     * @var \Qliro\QliroOne\Model\QliroOrder\Converter\AddressConverter
     */
    private $addressConverter;

    /**
     * @var \Qliro\QliroOne\Helper\Data
     */
    private $helper;

    /**
     * Inject dependnecies
     *
     * @param \Qliro\QliroOne\Model\QliroOrder\Converter\AddressConverter $addressConverter
     * @param \Qliro\QliroOne\Helper\Data $helper
     */
    public function __construct(
        AddressConverter $addressConverter,
        Helper $helper
    ) {
        $this->addressConverter = $addressConverter;
        $this->helper = $helper;
    }

    /**
     * Convert update shipping methods request into quote
     *
     * @param \Qliro\QliroOne\Api\Data\UpdateShippingMethodsNotificationInterface $container
     * @param \Magento\Quote\Model\Quote $quote
     */
    public function convert(UpdateShippingMethodsNotificationInterface $container, Quote $quote)
    {
        $customer = $container->getCustomer();
        $qliroAddress = $container->getShippingAddress();

        // This callback is not ordered against the browser call that stores the address, so it
        // must rate what its own payload carries. Qliro can put the address on the customer
        // rather than on ShippingAddress while the buyer is still identifying.
        if (!$qliroAddress && $customer) {
            $qliroAddress = $customer->getAddress();
        }

        $billingAddress = $quote->getBillingAddress();

        $this->addressConverter->convert(
            $qliroAddress,
            $customer,
            $billingAddress,
            $container->getCountryCode()
        );

        if (!$quote->isVirtual()) {
            $shippingAddress = $quote->getShippingAddress();

            $this->addressConverter->convert(
                $qliroAddress,
                $customer,
                $shippingAddress,
                $container->getCountryCode()
            );
            $shippingAddress->setSameAsBilling($this->helper->doAddressesMatch($shippingAddress, $billingAddress));
        }
    }
}
