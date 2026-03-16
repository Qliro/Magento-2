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
     * Convert given quote address from raw QliroOne address and customer arrays
     *
     * @param array $qliroAddress
     * @param array $qliroCustomer
     * @param \Magento\Quote\Model\Quote\Address $address
     * @param string|null $countryCode
     */
    public function convert(
        array $qliroAddress,
        array $qliroCustomer,
        Address $address,
        ?string $countryCode = null
    ): void {
        $addressData = [
            'firstname' => $qliroAddress['FirstName'] ?? null,
            'lastname'  => $qliroAddress['LastName'] ?? null,
            'email'     => $qliroCustomer['Email'] ?? null,
            'care_of'   => $qliroAddress['CareOf'] ?? null, // Is ignored for now if no attribute
            'street'    => $qliroAddress['Street'] ?? null,
            'telephone' => $qliroCustomer['MobileNumber'] ?? null,
            'city'      => $qliroAddress['City'] ?? null,
            'postcode'  => $qliroAddress['PostalCode'] ?? null,
            'company'   => $qliroAddress['CompanyName'] ?? null,
        ];

        $changed = false;
        foreach ($addressData as $key => $value) {
            if ($value !== null && $address->getData($key) != $value) {
                $address->setData($key, $value);
                $changed = true;
            }
        }

        if (!$address->getCountryId() && $countryCode !== null) {
            $address->setCountryId($countryCode);
            $changed = true;
        }

        if ($changed && $address->getCustomerAddressId()) {
            $address->setCustomerAddressId(null);
        }
    }
}
