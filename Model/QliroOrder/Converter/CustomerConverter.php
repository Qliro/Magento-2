<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Converter;

use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Helper\Data as Helper;

/**
 * QliroOne Order customer Converter class
 */
class CustomerConverter
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
     * Convert QliroOne order customer info (raw array) into quote customer
     *
     * @param array $qliroCustomer
     * @param \Magento\Quote\Model\Quote $quote
     */
    public function convert(array $qliroCustomer, Quote $quote): void
    {
        if (empty($qliroCustomer) || !isset($qliroCustomer['Email'])) {
            return;
        }

        $customer = $quote->getCustomer();
        $customer->setData('email', $qliroCustomer['Email']);

        $qliroAddress = $qliroCustomer['Address'] ?? [];
        if ($qliroAddress) {
            $billingAddress = $quote->getBillingAddress();
            $this->addressConverter->convert($qliroAddress, $qliroCustomer, $billingAddress);

            if (!$quote->isVirtual()) {
                $shippingAddress = $quote->getShippingAddress();
                $this->addressConverter->convert($qliroAddress, $qliroCustomer, $shippingAddress);
                $shippingAddress->setSameAsBilling($this->helper->doAddressesMatch($shippingAddress, $billingAddress));
            }
        }
    }
}
