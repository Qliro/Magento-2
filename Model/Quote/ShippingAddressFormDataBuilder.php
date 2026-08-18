<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Quote;

use Magento\Quote\Model\Quote;

/**
 * Builds the quote shipping address in the shape the Magento checkout JS expects
 *
 * The QliroOne checkout has no address form, so the browser side quote never learns the
 * address Qliro collected. This is what the updateCustomer response hands back so the
 * frontend can put the real address into the client side quote.
 */
class ShippingAddressFormDataBuilder
{
    /**
     * Build the form data for the quote shipping address, or null if it cannot be rated yet
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return array|null
     */
    public function build(Quote $quote): ?array
    {
        if ($quote->isVirtual()) {
            return null;
        }

        $address = $quote->getShippingAddress();

        // Without these two Magento cannot collect a single rate, so there is nothing
        // worth sending to the frontend.
        if (!$address->getPostcode() || !$address->getCountryId()) {
            return null;
        }

        return [
            'firstname' => $address->getFirstname(),
            'lastname' => $address->getLastname(),
            'company' => $address->getCompany(),
            'street' => $address->getStreet(),
            'city' => $address->getCity(),
            'postcode' => $address->getPostcode(),
            'region' => $address->getRegion(),
            'region_id' => $address->getRegionId(),
            'country_id' => $address->getCountryId(),
            'telephone' => $address->getTelephone(),
            'email' => $address->getEmail(),
            'save_in_address_book' => 0,
        ];
    }
}
