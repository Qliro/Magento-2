<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Customer\Model\Address\AbstractAddress;

/**
 * QliroOne Order Customer Address builder class
 */
class CustomerAddressBuilder
{
    const STREET_ADDRESS_SEPARATOR = '; ';

    private ?AbstractAddress $address = null;

    public function __construct()
    {
    }

    /**
     * Set an address to extract data
     *
     * @param \Magento\Customer\Model\Address\AbstractAddress $address
     * @return $this
     */
    public function setAddress(AbstractAddress $address): static
    {
        $this->address = $address;

        return $this;
    }

    /**
     * Create a container
     *
     * @return array
     */
    public function create(): array
    {
        if (empty($this->address)) {
            throw new \LogicException('Address entity is not set.');
        }

        $streetAddress = trim(implode(self::STREET_ADDRESS_SEPARATOR, $this->address->getStreet()));

        $qliroOrderCustomerAddress = [
            'FirstName' => (string)$this->address->getFirstname(),
            'LastName' => (string)$this->address->getLastname(),
            'CompanyName' => (string)$this->address->getCompany(),
            'Street' => (string)$streetAddress,
            'PostalCode' => str_replace(' ', '', (string)$this->address->getPostcode()),
            'City' => (string)$this->address->getCity(),
        ];

        $this->address = null;

        return $qliroOrderCustomerAddress;
    }

}
