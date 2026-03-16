<?php declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Interface for Merchant Payment Customer Data Model
 */
interface MerchantPaymentCustomerInterface
{
    const JURIDICAL_TYPE_PHYSICAL = 'Physical';

    const JURIDICAL_TYPE_COMPANY = 'Company';

    /**
     * @param string $personalNumber
     * @return self
     */
    public function setPersonalNumber(string $personalNumber): self;

    /**
     * @param string $vatNumber
     * @return self
     */
    public function setVatNumber(string $vatNumber): self;

    /**
     * @param string $email
     * @return self
     */
    public function setEmail(string $email): self;

    /**
     * @param string $type
     * @return self
     */
    public function setJuridicalType(string $type): self;

    /**
     * @param string $number
     * @return self
     */
    public function setMobileNumber(string $number): self;

    /**
     * @return string|null
     */
    public function getPersonalNumber(): ?string;

    /**
     * @return string|null
     */
    public function getVatNumber(): ?string;

    /**
     * @return string
     */
    public function getEmail(): string;

    /**
     * @return string
     */
    public function getJuridicalType(): string;

    /**
     * @return string
     */
    public function getMobileNumber(): string;
}
