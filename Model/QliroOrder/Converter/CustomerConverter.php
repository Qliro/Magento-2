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
 * QliroOne Order customer Converter class
 */
class CustomerConverter
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
                $shippingAddress->setSameAsBilling($this->addressComparator->match($shippingAddress, $billingAddress));
            }
        }
    }
}
