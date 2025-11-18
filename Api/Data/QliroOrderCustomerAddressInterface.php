<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Api\Data;

/**
 * QliroOne Order CustomerInfo Address interface
 *
 * @api
 */
interface QliroOrderCustomerAddressInterface extends ContainerInterface
{
    /**
     * @return string
     */
    public function getFirstName(): string;

    /**
     * @return string
     */
    public function getLastName(): string;

    /**
     * @return string
     */
    public function getCareOf(): string;

    /**
     * @return string
     */
    public function getCompanyName(): string;

    /**
     * @return string
     */
    public function getStreet(): string;

    /**
     * @return string
     */
    public function getPostalCode(): string;

    /**
     * @return string
     */
    public function getCity(): string;

    /**
     * @return string
     */
    public function getCountryCode(): string;

    /**
     * @param string $value
     * @return $this
     */
    public function setFirstName(string $value): static;

    /**
     * @param string $value
     * @return $this
     */
    public function setLastName(string $value): static;

    /**
     * @param string $value
     * @return $this
     */
    public function setCareOf(string $value): static;

    /**
     * @param string $value
     * @return $this
     */
    public function setCompanyName(string $value): static;

    /**
     * @param string $value
     * @return $this
     */
    public function setStreet(string $value): static;

    /**
     * @param string $value
     * @return $this
     */
    public function setPostalCode(string $value): static;

    /**
     * @param string $value
     * @return $this
     */
    public function setCity(string $value): static;

    /**
     * @param string $value
     * @return $this
     */
    public function setCountryCode(string $value): static;
}
