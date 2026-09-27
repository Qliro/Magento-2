<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

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

        $streetAddress = trim(implode(self::STREET_ADDRESS_SEPARATOR, $this->address->getStreet()));

        $qliroOrderCustomerAddress->setFirstName($this->address->getFirstname());
        $qliroOrderCustomerAddress->setLastName($this->address->getLastname());
        $qliroOrderCustomerAddress->setCompanyName($this->address->getCompany());
        $qliroOrderCustomerAddress->setStreet($streetAddress);
        $qliroOrderCustomerAddress->setPostalCode(str_replace(' ', '', (string)$this->address->getPostcode()));
        $qliroOrderCustomerAddress->setCity($this->address->getCity());

        $this->address = null;

        return $qliroOrderCustomerAddress;
    }

}
