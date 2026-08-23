<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Converter;

use Magento\Quote\Model\Quote\Address;

/**
 * QliroOne order address converter class
 */
class AddressConverter
{
    /**
     * Convert given quote address from QliroOne address and other parameters
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterface $qliroAddress
     * @param \Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface $qliroCustomer
     * @param \Magento\Quote\Model\Quote\Address $address
     * @param string|null $countryCode
     * @return bool Whether any value on the quote address was changed
     */
    public function convert(
        $qliroAddress,
        $qliroCustomer,
        Address $address,
        $countryCode = null
    ) {
        $addressData = [
            'firstname' => $qliroAddress ? $qliroAddress->getFirstName() : null,
            'lastname' => $qliroAddress ? $qliroAddress->getLastName() : null,
            'email' => $qliroCustomer? $qliroCustomer->getEmail() : null,
            'care_of' => $qliroAddress ? $qliroAddress->getCareOf() : null, // Is ignored for now if no attribute
            'street' => $qliroAddress ? $qliroAddress->getStreet() : null,
            'telephone' => $qliroCustomer ? $qliroCustomer->getMobileNumber() : null,
            'city' => $qliroAddress ? $qliroAddress->getCity() : null,
            'postcode' => $qliroAddress ? $qliroAddress->getPostalCode() : null,
            'company' => $qliroAddress ? $qliroAddress->getCompanyName() : null,
        ];

        $changed = false;
        foreach ($addressData as $key => $value) {
            if ($value !== null && $address->getData($key) != $value) {
                $address->setData($key, $value);
                $changed = true;
            }
        }

        // Qliro owns the country, the buyer can change it after the order was created. Replacing
        // one takes a payload that also brings the postcode, otherwise the quote would keep the
        // postcode of the country being replaced, which is the very failure this fixes.
        $currentCountry = $address->getCountryId();
        $mayReplaceCountry = !$currentCountry || ($qliroAddress && $qliroAddress->getPostalCode());

        if (!empty($countryCode) && $mayReplaceCountry && $currentCountry != $countryCode) {
            $address->setCountryId($countryCode);

            // A region belongs to the country it was picked in
            if ($currentCountry) {
                $address->setRegion(null);
                $address->setRegionId(null);
            }

            $changed = true;
        }

        if ($changed && $address->getCustomerAddressId()) {
            $address->setCustomerAddressId(null);
        }

        return $changed;
    }
}
