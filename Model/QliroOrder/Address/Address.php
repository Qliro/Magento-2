<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Address;

use Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterface;

/**
 * QliroOne order customer address class
 */
class Address implements QliroOrderCustomerAddressInterface
{
    /**
     * @var string
     */
    private string $firstName = '';

    /**
     * @var string
     */
    private string $lastName = '';

    /**
     * @var string
     */
    private string $careOf = '';

    /**
     * @var string
     */
    private string $companyName = '';

    /**
     * @var string
     */
    private string $street = '';

    /**
     * @var string
     */
    private string $postalCode = '';

    /**
     * @var string
     */
    private string $city = '';

    /**
     * @var string
     */
    private string $countryId = '';

    /**
     * @inheirtDoc
     */
    public function getFirstName(): string
    {
        return $this->firstName;
    }

    /**
     * @inheirtDoc
     */
    public function setFirstName(string $value): static
    {
        $this->firstName = $value;

        return $this;
    }

    /**
     * @inheirtDoc
     */
    public function getLastName(): string
    {
        return $this->lastName;
    }

    /**
     * @inheirtDoc
     */
    public function setLastName(string $value): static
    {
        $this->lastName = $value;

        return $this;
    }

    /**
     * @inheirtDoc
     */
    public function getCareOf(): string
    {
        return $this->careOf;
    }

    /**
     * @inheirtDoc
     */
    public function setCareOf(string $value): static
    {
        $this->careOf = $value;

        return $this;
    }

    /**
     * @inheirtDoc
     */
    public function getCompanyName(): string
    {
        return $this->companyName;
    }

    /**
     * @inheirtDoc
     */
    public function setCompanyName(string $value): static
    {
        $this->companyName = $value;

        return $this;
    }

    /**
     * @inheirtDoc
     */
    public function getStreet(): string
    {
        return $this->street;
    }

    /**
     * @inheirtDoc
     */
    public function setStreet(string $value): static
    {
        $this->street = $value;

        return $this;
    }

    /**
     * @inheirtDoc
     */
    public function getPostalCode(): string
    {
        return $this->postalCode;
    }

    /**
     * @inheirtDoc
     */
    public function setPostalCode(string $value): static
    {
        $this->postalCode = $value;

        return $this;
    }

    /**
     * @inheirtDoc
     */
    public function getCity(): string
    {
        return $this->city;
    }

    /**
     * @inheirtDoc
     */
    public function setCity(string $value): static
    {
        $this->city = $value;

        return $this;
    }

    /**
     * @inheirtDoc
     */
    public function getCountryCode(): string
    {
        return $this->countryId;
    }

    /**
     * @inheirtDoc
     */
    public function setCountryCode(string $value): static
    {
        $this->countryId = $value;

        return $this;
    }
}
