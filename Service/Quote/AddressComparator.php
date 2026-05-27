<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Service\Quote;

use Magento\Quote\Model\Quote\Address;

/**
 * Compares two quote addresses field-by-field.
 */
class AddressComparator
{
    /**
     * Return true when both addresses contain identical field values.
     *
     * @param Address $address1
     * @param Address $address2
     * @return bool
     */
    public function match(Address $address1, Address $address2): bool
    {
        $fields = [
            'email'      => [$address1->getEmail(),      $address2->getEmail()],
            'firstname'  => [$address1->getFirstname(),  $address2->getFirstname()],
            'lastname'   => [$address1->getLastname(),   $address2->getLastname()],
            'care_of'    => [$address1->getCareOf(),     $address2->getCareOf()],
            'company'    => [$address1->getCompany(),    $address2->getCompany()],
            'street'     => [$address1->getStreetFull(), $address2->getStreetFull()],
            'city'       => [$address1->getCity(),       $address2->getCity()],
            'region'     => [$address1->getRegion(),     $address2->getRegion()],
            'region_id'  => [$address1->getRegionId(),   $address2->getRegionId()],
            'postcode'   => [$address1->getPostcode(),   $address2->getPostcode()],
            'country_id' => [$address1->getCountryId(),  $address2->getCountryId()],
            'telephone'  => [$address1->getTelephone(),  $address2->getTelephone()],
        ];

        foreach ($fields as [$v1, $v2]) {
            if ($v1 !== $v2) {
                return false;
            }
        }

        return true;
    }
}
