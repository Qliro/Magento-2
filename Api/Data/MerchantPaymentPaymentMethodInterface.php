<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Merchant Payment Method interface
 *
 * @api
 */
interface MerchantPaymentPaymentMethodInterface
{
    const string NAME_CREDITCARDS = 'CREDITCARDS';
    const string NAME_INVOICE     = 'QLIRO_INVOICE';
    const string SUBTYPE_INVOICE  = 'INVOICE';

    /**
     * Get a payment method name (CREDITCARDS or QLIRO_INVOICE)
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Get payment subtype (INVOICE, required when the method is QLIRO_INVOICE)
     *
     * @return string|null
     */
    public function getSubType(): ?string;

    /**
     * Get whether the letter invoice option is selected
     *
     * @return bool
     */
    public function getSelectedLetterInvoiceOption(): bool;

    /**
     * Get merchant-saved credit card ID (required when using CREDITCARDS)
     *
     * @return string
     */
    public function getMerchantSavedCreditCardId(): string;

    /**
     * Set a payment method name
     *
     * @param string $name
     * @return static
     */
    public function setName(string $name): static;

    /**
     * Set payment subtype
     *
     * @param string $subType
     * @return static
     */
    public function setSubType(string $subType): static;

    /**
     * Set whether the letter invoice option is selected
     *
     * @param bool $value
     * @return static
     */
    public function setSelectedLetterInvoiceOption(bool $value): static;

    /**
     * Set merchant-saved credit card ID
     *
     * @param string $id
     * @return static
     */
    public function setMerchantSavedCreditCardId(string $id): static;
}
