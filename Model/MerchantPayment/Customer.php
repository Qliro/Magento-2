<?php declare(strict_types=1);

namespace Qliro\QliroOne\Model\MerchantPayment;

use Qliro\QliroOne\Api\Data\MerchantPaymentCustomerInterface;

/**
 * Merchant Payment Customer Data Model
 */
class Customer implements MerchantPaymentCustomerInterface
{
    private ?string $personalNumber = null;

    private ?string $vatNumber = null;

    private string $email = '';

    private string $juridicalType = self::JURIDICAL_TYPE_PHYSICAL;

    private string $mobileNumber = '';

    /**
     * @inheritDoc
     */
    public function setPersonalNumber(string $personalNumber): static
    {
        $this->personalNumber = $personalNumber;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setVatNumber(string $vatNumber): static
    {
        $this->vatNumber = $vatNumber;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setEmail(string $email): static
    {
        $this->email = $email;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setJuridicalType(string $type): static
    {
        $this->juridicalType = $type;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setMobileNumber(string $number): static
    {
        $this->mobileNumber = $number;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getPersonalNumber(): ?string
    {
        return $this->personalNumber;
    }

    /**
     * @inheritDoc
     */
    public function getVatNumber(): ?string
    {
        return $this->vatNumber;
    }

    /**
     * @inheritDoc
     */
    public function getJuridicalType(): string
    {
        return $this->juridicalType;
    }

    /**
     * @inheritDoc
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * @inheritDoc
     */
    public function getMobileNumber(): string
    {
        return $this->mobileNumber;
    }
}
