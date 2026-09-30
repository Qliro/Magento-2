<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Converter;

use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\Data\ValidateOrderNotificationInterface;

/**
 * Quote from validate order container converter class
 */
class QuoteFromValidateConverter
{
    /**
     * @var \Qliro\QliroOne\Model\QliroOrder\Converter\AddressConverter
     */
    private $addressConverter;

    /**
     * Inject dependnecies
     *
     * @param \Qliro\QliroOne\Model\QliroOrder\Converter\AddressConverter $addressConverter
     */
    public function __construct(
        AddressConverter $addressConverter
    ) {
        $this->addressConverter = $addressConverter;
    }

    /**
     * Convert validate order request into quote
     *
     * @param \Qliro\QliroOne\Api\Data\ValidateOrderNotificationInterface $container
     * @param \Magento\Quote\Model\Quote $quote
     */
    public function convert(ValidateOrderNotificationInterface $container, Quote $quote)
    {
        $shippingAddress = $quote->getShippingAddress();

        if ($quote->isVirtual()) {
            $shippingAddress->setShippingMethod($container->getSelectedShippingMethod());
        }

        /*
         * For every quote, not only a virtual one. Qliro masks the buyer's street and city until
         * they identify, so a quote can reach this callback with a country and a postcode and
         * nothing else, and this request is the first place the rest of the address is readable.
         * A carrier that answers only a complete destination then returns nothing, the delivery
         * Qliro states is not among the rates, and the order is declined over an address the
         * buyer had entered minutes earlier. One merchant's carrier refuses on a missing street
         * or city outright, which made every one of those declines take milliseconds and look
         * like the carrier had never been asked (PLIN-376).
         */
        $this->addressConverter->convert(
            $container->getShippingAddress(),
            $container->getCustomer(),
            $shippingAddress
        );
    }
}
