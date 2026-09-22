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
     * The organisation number is taken off the company name here and written to the order by
     * `Model\Order\OrganizationNumber`, not to the quote: `vat_id` on a quote address is what
     * Magento validates against VIES when automatic customer group assignment is on, and an
     * organisation number is not a VAT number, so the buyer would be moved to the group a store
     * keeps for an invalid VAT id, with whatever tax class that group carries.
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
        $company = $qliroAddress ? $qliroAddress->getCompanyName() : null;
        $organizationNumbers = $this->getOrganizationNumbers($qliroCustomer, $company);

        $addressData = [
            'firstname' => $qliroAddress ? $qliroAddress->getFirstName() : null,
            'lastname' => $qliroAddress ? $qliroAddress->getLastName() : null,
            'email' => $qliroCustomer? $qliroCustomer->getEmail() : null,
            'care_of' => $qliroAddress ? $qliroAddress->getCareOf() : null, // Is ignored for now if no attribute
            'street' => $qliroAddress ? $qliroAddress->getStreet() : null,
            'telephone' => $qliroCustomer ? $qliroCustomer->getMobileNumber() : null,
            'city' => $qliroAddress ? $qliroAddress->getCity() : null,
            'postcode' => $qliroAddress ? $qliroAddress->getPostalCode() : null,
            'company' => $this->stripOrganizationNumber($company, $organizationNumbers),
        ];

        $changed = false;
        foreach ($addressData as $key => $value) {
            if ($value !== null && $address->getData($key) != $value) {
                $address->setData($key, $value);
                $changed = true;
            }
        }

        $changed = $this->clearCompanyOfAPrivateBuyer($qliroAddress, $address) || $changed;

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

    /**
     * The organisation number of a company buyer, null for a buyer who is not one
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterface|null $qliroAddress
     * @param \Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface|null $qliroCustomer
     * @return string|null
     */
    public function organizationNumber($qliroAddress, $qliroCustomer)
    {
        $company = $qliroAddress ? $qliroAddress->getCompanyName() : null;

        return $this->getOrganizationNumbers($qliroCustomer, $company)[0] ?? null;
    }

    /**
     * Read the numbers a company buyer was identified by, empty for a buyer who is not a company
     *
     * Qliro carries the organisation number in the customer's `PersonalNumber`, and the company
     * name is what says the number belongs to a company: that is the same rule `CustomerBuilder`
     * sends the juridical type by. Without a company the number is the buyer's own identity
     * number, which must never be written to `vat_id`. `VatNumber` is the field Qliro has for
     * this and wins where it is filled, but both are kept, because the one that answers for the
     * order is not always the one the company name was glued to.
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface|null $qliroCustomer
     * @param string|null $company
     * @return string[] The number for `vat_id` first
     */
    private function getOrganizationNumbers($qliroCustomer, $company): array
    {
        if (!$qliroCustomer || trim((string)$company) === '') {
            return [];
        }

        // The field is on the container, not on the interface, so anything else implementing it
        // keeps working.
        $numbers = [
            method_exists($qliroCustomer, 'getVatNumber') ? $qliroCustomer->getVatNumber() : null,
            $qliroCustomer->getPersonalNumber(),
        ];

        $numbers = array_filter(array_map(fn ($number) => trim((string)$number), $numbers), 'strlen');

        return array_values(array_unique($numbers));
    }

    /**
     * Take the organisation number off the front of the company name Qliro sent
     *
     * Qliro sends a business buyer as one string, `964969124 Gloppen Kommune`, so the number
     * reached the order printed on the company line. It is stripped only where the name really
     * starts with one of the numbers, digit by digit, so a name Qliro sends on its own is left
     * alone, and a name that is nothing but the number is kept as it is rather than emptied.
     *
     * @param string|null $company
     * @param string[] $organizationNumbers
     * @return string|null
     */
    private function stripOrganizationNumber($company, array $organizationNumbers)
    {
        if ($company === null) {
            return null;
        }

        foreach ($organizationNumbers as $organizationNumber) {
            $digits = preg_replace('/\D/', '', $organizationNumber);

            if ($digits === '') {
                continue;
            }

            // The lookahead is what keeps 964969124 off the front of "9649691240 AB": without it
            // the shorter number matches the longer one and leaves the rest of it on the name
            $pattern = sprintf(
                '/^\s*%s(?![\s.,:;\/-]*\d)[\s.,:;\/-]*/',
                implode('[\s.-]*', str_split($digits))
            );
            $stripped = trim((string)preg_replace($pattern, '', $company));

            if ($stripped !== '' && $stripped !== trim($company)) {
                return $stripped;
            }
        }

        return $company;
    }

    /**
     * Drop a company the buyer Qliro identified does not have
     *
     * The loop writes no null, so nothing could clear a company once it was on the quote: a buyer
     * who starts as a company and completes as themselves kept it, and so does a quote that took
     * the store name from the shipping placeholder of a release before 1.7.26. Only an address
     * carrying a postcode is read this way, because Qliro masks the address, company included,
     * until the buyer is identified, and an empty name there says nothing about them.
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterface|null $qliroAddress
     * @param \Magento\Quote\Model\Quote\Address $address
     * @return bool
     */
    private function clearCompanyOfAPrivateBuyer($qliroAddress, Address $address): bool
    {
        if (!$qliroAddress
            || empty($qliroAddress->getPostalCode())
            || trim((string)$qliroAddress->getCompanyName()) !== ''
        ) {
            return false;
        }

        if (trim((string)$address->getData('company')) === '') {
            return false;
        }

        $address->setData('company', null);

        return true;
    }
}
