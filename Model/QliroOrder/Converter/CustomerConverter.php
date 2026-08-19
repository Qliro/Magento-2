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
     * @var \Qliro\QliroOne\Model\QliroOrder\Converter\AddressConverter
     */
    private $addressConverter;

    /**
     * @var \Qliro\QliroOne\Helper\Data
     */
    private $helper;

    /**
     * Inject dependencies
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
     * Convert QliroOne order customer info into quote customer
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface $qliroCustomer
     * @param \Magento\Quote\Model\Quote $quote
     * @return bool Whether anything from the payload was applied to the quote
     */
    public function convert($qliroCustomer, Quote $quote)
    {
        if (!$qliroCustomer) {
            return false;
        }

        $applied = false;

        $email = $qliroCustomer->getEmail();

        // Compared, not just written: callers use the return value to decide whether the quote
        // is worth pushing to Qliro again, and a repeated payload must not look like a change.
        if ($email !== null && $quote->getCustomer()->getEmail() != $email) {
            $quote->getCustomer()->setData('email', $email);
            $applied = true;
        }

        // The address is applied on its own: Qliro can send it before the email is known,
        // and skipping it here left the quote without a postcode to rate shipping on.
        $qliroAddress = $qliroCustomer->getAddress() ?? null;

        if (!$qliroAddress) {
            return $applied;
        }

        $billingAddress = $quote->getBillingAddress();
        $applied = $this->addressConverter->convert($qliroAddress, $qliroCustomer, $billingAddress) || $applied;

        if (!$quote->isVirtual()) {
            $shippingAddress = $quote->getShippingAddress();
            $applied = $this->addressConverter->convert($qliroAddress, $qliroCustomer, $shippingAddress) || $applied;
            $shippingAddress->setSameAsBilling($this->helper->doAddressesMatch($shippingAddress, $billingAddress));
        }

        return $applied;
    }
}
