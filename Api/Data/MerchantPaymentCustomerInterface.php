<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Merchant Payment Customer interface
 *
 * @api
 */
interface MerchantPaymentCustomerInterface
{
    const string JURIDICAL_TYPE_PHYSICAL = 'Physical';
    const string JURIDICAL_TYPE_COMPANY  = 'Company';

    /**
     * Set a personal or organisation number
     *
     * @param string $personalNumber
     * @return static
     */
    public function setPersonalNumber(string $personalNumber): static;

    /**
     * Set a VAT number
     *
     * @param string $vatNumber
     * @return static
     */
    public function setVatNumber(string $vatNumber): static;

    /**
     * Set an email address
     *
     * @param string $email
     * @return static
     */
    public function setEmail(string $email): static;

    /**
     * Set juridical type (Physical or Company)
     *
     * @param string $type
     * @return static
     */
    public function setJuridicalType(string $type): static;

    /**
     * Set a mobile number
     *
     * @param string $number
     * @return static
     */
    public function setMobileNumber(string $number): static;

    /**
     * Get a personal or organisation number
     *
     * @return string|null
     */
    public function getPersonalNumber(): ?string;

    /**
     * Get VAT number
     *
     * @return string|null
     */
    public function getVatNumber(): ?string;

    /**
     * Get email address
     *
     * @return string
     */
    public function getEmail(): string;

    /**
     * Get juridical type
     *
     * @return string
     */
    public function getJuridicalType(): string;

    /**
     * Get a mobile number
     *
     * @return string
     */
    public function getMobileNumber(): string;
}
