<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Customer\Model\Address\AbstractAddress;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterfaceFactory;

/**
 * QliroOne Order Customer Address builder class
 */
class CustomerAddressBuilder
{
    const STREET_ADDRESS_SEPARATOR = '; ';

    /**
     * A street line carrying the c/o, the way `AddressConverter` writes it
     */
    const CARE_OF_PATTERN = '/^c\/o\s+/i';

    /**
     * @var \Magento\Customer\Model\Address\AbstractAddress
     */
    private $address;

    /**
     * @var \Qliro\QliroOne\Api\Data\QliroOrderCustomerInterfaceFactory
     */
    private $orderCustomerAddressFactory;

    /**
     * Inject dependencies
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterfaceFactory $orderCustomerAddressFactory
     */
    public function __construct(QliroOrderCustomerAddressInterfaceFactory $orderCustomerAddressFactory)
    {
        $this->orderCustomerAddressFactory = $orderCustomerAddressFactory;
    }

    /**
     * Set an address to extract data
     *
     * @param \Magento\Customer\Model\Address\AbstractAddress $address
     * @return $this
     */
    public function setAddress(AbstractAddress $address)
    {
        $this->address = $address;

        return $this;
    }

    /**
     * Create a container
     *
     * @return \Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterface
     */
    public function create()
    {
        if (empty($this->address)) {
            throw new \LogicException('Address entity is not set.');
        }

        /** @var \Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterface $qliroOrderCustomerAddress */
        $qliroOrderCustomerAddress = $this->orderCustomerAddressFactory->create();

        $streetLines = $this->address->getStreet();
        $careOf = $this->takeCareOf($streetLines);
        $streetAddress = trim(implode(self::STREET_ADDRESS_SEPARATOR, $streetLines));

        $qliroOrderCustomerAddress->setFirstName($this->address->getFirstname());
        $qliroOrderCustomerAddress->setLastName($this->address->getLastname());
        $qliroOrderCustomerAddress->setCompanyName($this->address->getCompany());
        $qliroOrderCustomerAddress->setStreet($streetAddress);
        $qliroOrderCustomerAddress->setPostalCode(str_replace(' ', '', (string)$this->address->getPostcode()));
        $qliroOrderCustomerAddress->setCity($this->address->getCity());

        if ($careOf !== null) {
            $qliroOrderCustomerAddress->setCareOf($careOf);
        }

        $this->address = null;

        return $qliroOrderCustomerAddress;
    }

    /**
     * Take the c/o line off the street, so Qliro gets it in its own field and not in the street
     *
     * The first line is always the street, so a lone line is never read as a c/o.
     *
     * @param string[] $streetLines
     * @return string|null The c/o without its prefix
     */
    private function takeCareOf(array &$streetLines)
    {
        foreach ($streetLines as $index => $line) {
            if ($index === 0) {
                continue;
            }

            $careOf = trim((string)preg_replace(self::CARE_OF_PATTERN, '', trim((string)$line), 1, $count));

            if ($count && $careOf !== '') {
                unset($streetLines[$index]);
                $streetLines = array_values($streetLines);

                return $careOf;
            }
        }

        return null;
    }
}
