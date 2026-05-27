<?php declare(strict_types=1);

namespace Qliro\QliroOne\Model\MerchantPayment;

use Qliro\QliroOne\Api\Data\MerchantPaymentPaymentMethodInterface;

/**
 * Merchant Payment – Payment Method Data Model
 * @link https://developers.qliro.com/docs/api/v2merchantpayment-post
 */
class PaymentMethod implements MerchantPaymentPaymentMethodInterface
{
    /**
     * @var string
     */
    private string $name = '';

    /**
     * @var string|null
     */
    private ?string $subType = null;

    /**
     * @var bool
     */
    private bool $selectedLetterInvoiceOption = false;

    /**
     * @var string
     */
    private string $merchantSavedCreditCardId = '';

    /**
     * @inheritDoc
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @inheritDoc
     */
    public function getSubType(): ?string
    {
        return $this->subType;
    }

    /**
     * @inheritDoc
     */
    public function getSelectedLetterInvoiceOption(): bool
    {
        return $this->selectedLetterInvoiceOption;
    }

    /**
     * @inheritDoc
     */
    public function getMerchantSavedCreditCardId(): string
    {
        return $this->merchantSavedCreditCardId;
    }

    /**
     * @inheritDoc
     */
    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setSubType(string $subType): static
    {
        $this->subType = $subType;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setSelectedLetterInvoiceOption(bool $value): static
    {
        $this->selectedLetterInvoiceOption = $value;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setMerchantSavedCreditCardId(string $id): static
    {
        $this->merchantSavedCreditCardId = $id;
        return $this;
    }
}
