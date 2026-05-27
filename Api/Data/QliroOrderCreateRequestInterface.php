<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Qliro Order Create Request interface
 *
 * @api
 */
interface QliroOrderCreateRequestInterface
{
    /**
     * @return string|null
     */
    public function getMerchantReference(): ?string;

    /**
     * @return string|null
     */
    public function getMerchantApiKey(): ?string;

    /**
     * @return string|null
     */
    public function getCountry(): ?string;

    /**
     * @return string|null
     */
    public function getCurrency(): ?string;

    /**
     * @return string|null
     */
    public function getLanguage(): ?string;

    /**
     * @return string|null
     */
    public function getMerchantConfirmationUrl(): ?string;

    /**
     * @return string|null
     */
    public function getMerchantTermsUrl(): ?string;

    /**
     * @return \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[]
     */
    public function getOrderItems(): array;

    /**
     * @return string|null
     */
    public function getMerchantCheckoutStatusPushUrl(): ?string;

    /**
     * @return string|null
     */
    public function getMerchantSavedCreditCardPushUrl(): ?string;

    /**
     * @return string|null
     */
    public function getMerchantOrderManagementStatusPushUrl(): ?string;

    /**
     * @return string|null
     */
    public function getMerchantNotificationUrl(): ?string;

    /**
     * @return string|null
     */
    public function getMerchantOrderValidationUrl(): ?string;

    /**
     * @return string|null
     */
    public function getMerchantOrderAvailableShippingMethodsUrl(): ?string;

    /**
     * @return string|null
     */
    public function getMerchantIntegrityPolicyUrl(): ?string;

    /**
     * @return string|null
     */
    public function getBackgroundColor(): ?string;

    /**
     * @return string|null
     */
    public function getPrimaryColor(): ?string;

    /**
     * @return string|null
     */
    public function getCallToActionColor(): ?string;

    /**
     * @return string|null
     */
    public function getCallToActionHoverColor(): ?string;

    /**
     * @return int|null
     */
    public function getCornerRadius(): ?int;

    /**
     * @return int|null
     */
    public function getButtonCornerRadius(): ?int;

    /**
     * @return mixed
     */
    public function getCustomerInformation(): mixed;

    /**
     * @return string|null
     */
    public function getEnforcedJuridicalType(): ?string;

    /**
     * @return array|null
     */
    public function getAvailableShippingMethods(): ?array;

    /**
     * @return \Qliro\QliroOne\Api\Data\QliroOrderShippingConfigInterface|null
     */
    public function getShippingConfiguration(): ?QliroOrderShippingConfigInterface;

    /**
     * @return int|null
     */
    public function getMinimumCustomerAge(): ?int;

    /**
     * @return bool|null
     */
    public function getAskForNewsletterSignup(): ?bool;

    /**
     * @return bool|null
     */
    public function getAskForNewsletterSignupChecked(): ?bool;

    /**
     * @return bool|null
     */
    public function getRequireIdentityVerification(): ?bool;

    /**
     * @param mixed $value
     * @return static
     */
    public function setMerchantReference(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setMerchantApiKey(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setCountry(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setCurrency(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setLanguage(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setMerchantConfirmationUrl(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setMerchantTermsUrl(mixed $value): static;

    /**
     * @param \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[] $value
     * @return static
     */
    public function setOrderItems(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setMerchantCheckoutStatusPushUrl(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setMerchantSavedCreditCardPushUrl(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setMerchantOrderManagementStatusPushUrl(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setMerchantNotificationUrl(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setMerchantOrderValidationUrl(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setMerchantOrderAvailableShippingMethodsUrl(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setMerchantIntegrityPolicyUrl(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setBackgroundColor(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setPrimaryColor(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setCallToActionColor(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setCallToActionHoverColor(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setCornerRadius(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setButtonCornerRadius(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setCustomerInformation(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setEnforcedJuridicalType(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setAvailableShippingMethods(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setShippingConfiguration(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setMinimumCustomerAge(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setAskForNewsletterSignup(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setAskForNewsletterSignupChecked(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setRequireIdentityVerification(mixed $value): static;
}
