<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Api\Data;

/**
 * Interface for Payment Method Data Model, for Merchant Payments
 *
 * @api
 */
interface MerchantPaymentPaymentMethodInterface
{
    const NAME_CREDITCARDS = 'CREDITCARDS';

    const NAME_INVOICE = 'QLIRO_INVOICE';

    const SUBTYPE_INVOICE = 'INVOICE';

    /**
     * Can be 'CREDITCARDS' or 'QLIRO_INVOICE'
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Can be 'INVOICE', required only of main method is 'QLIRO_INVOICE'
     *
     * @return string|null
     */
    public function getSubType(): ?string;

    /**
     * @return bool
     */
    public function getSelectedLetterInvoiceOption(): bool;

    /**
     * Required if CREDITCARDS will be used to identify customer
     *
     * @return string
     */
    public function getMerchantSavedCreditCardId(): string;

    /**
     * @param string $name
     * @return self
     */
    public function setName(string $name): self;

    /**
     * @param string $subType
     * @return self
     */
    public function setSubType(string $subType): self;

    /**
     * @param bool $value
     * @return self
     */
    public function setSelectedLetterInvoiceOption(bool $value): self;

    /**
     * @param string $id
     * @return self
     */
    public function setMerchantSavedCreditCardId(string $id): self;
}
