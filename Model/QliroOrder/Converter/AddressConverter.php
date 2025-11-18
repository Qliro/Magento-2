<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Converter;

use Magento\Quote\Model\Quote\Address;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterface as QliroOrderCustomerAddress;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface as QliroOrderCustomer;

/**
 * QliroOne order address converter class
 */
class AddressConverter
{
    /**
     * Convert given quote address from QliroOne address and other parameters
     *
     * @param QliroOrderCustomerAddress $qliroAddress
     * @param QliroOrderCustomer        $qliroCustomer
     * @param Address                   $address
     * @param string|null               $countryCode
     */
    public function convert(
        QliroOrderCustomerAddress $qliroAddress,
        QliroOrderCustomer        $qliroCustomer,
        Address                   $address,
        string                    $countryCode = null
    ): void {
        $addressData = [
            'firstname'  => $qliroAddress?->getFirstName(),
            'lastname'   => $qliroAddress?->getLastName(),
            'email'      => $qliroCustomer?->getEmail(),
            'care_of'    => $qliroAddress?->getCareOf(), // Is ignored for now if no attribute
            'street'     => $qliroAddress?->getStreet(),
            'telephone'  => $qliroCustomer?->getMobileNumber(),
            'city'       => $qliroAddress?->getCity(),
            'country_id' => $qliroAddress?->getCountryCode(),
            'postcode'   => $qliroAddress?->getPostalCode(),
            'company'    => $qliroAddress?->getCompanyName(),
        ];

        $changed = false;
        foreach ($addressData as $key => $value) {
            if (null !== $value && $address->getData($key) != $value) {
                $address->setData($key, $value);
                $changed = true;
            }
        }

        if (null !== $countryCode && !$address->getCountryId()) {
            $address->setCountryId($countryCode);
            $changed = true;
        }

        if ($changed && $address->getCustomerAddressId()) {
            $address->setCustomerAddressId(null);
        }
    }
}
