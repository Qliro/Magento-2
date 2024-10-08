<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder;

use Qliro\QliroOne\Api\Data\QliroOrderCreateRequestInterface;

/**
 * QliroOne Order Create Request concrete implementation
 */
class CreateRequest implements QliroOrderCreateRequestInterface
{
    // Qliro requires in some cases https for callbacks etc, which might not be possible when running locally
    const TEST_LOCAL_URL = false;

    /**
     * @var string
     */
    private $merchantReference;

    /**
     * @var string
     */
    private $merchantApiKey;

    /**
     * @var string
     */
    private $country;

    /**
     * @var string
     */
    private $currency;

    /**
     * @var string
     */
    private $language;

    /**
     * @var string
     */
    private $merchantConfirmationUrl;

    /**
     * @var string
     */
    private $merchantTermsUrl;

    /**
     * @var \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[]
     */
    private $orderItems;

    /**
     * @var string
     */
    private $merchantCheckoutStatusPushUrl;

    /**
     * @var ?string
     */
    private ?string $merchantSavedCreditCardPushUrl = null;

    /**
     * @var string
     */
    private $merchantOrderManagementStatusPushUrl;

    /**
     * @var string
     */
    private $merchantNotificationUrl;

    /**
     * @var string
     */
    private $merchantOrderValidationUrl;

    /**
     * @var string
     */
    private $merchantOrderAvailableShippingMethodsUrl;

    /**
     * @var string
     */
    private $merchantIntegrityPolicyUrl;

    /**
     * @var string
     */
    private $backgroundColor;

    /**
     * @var string
     */
    private $primaryColor;

    /**
     * @var string
     */
    private $callToActionColor;

    /**
     * @var string
     */
    private $callToActionHoverColor;

    /**
     * @var int
     */
    private $cornerRadius;

    /**
     * @var int
     */
    private $buttonCornerRadius;

    /**
     * @var \Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface
     */
    private $customerInformation;

    /**
     * @var string
     */
    private $availableShippingMethods;

    /**
     * @var \Qliro\QliroOne\Api\Data\QliroOrderShippingConfigInterface
     */
    private $shippingConfiguration;

    /**
     * @var int
     */
    private $minimumCustomerAge;

    /**
     * @var bool
     */
    private $askForNewsletterSignup;

    /**
     * @var bool
     */
    private $askForNewsletterSignupChecked;

    /**
     * @var bool
     */
    private $requireIdentityVerification;

    /**
     * Getter.
     *
     * @return string
     */
    public function getMerchantReference()
    {
        return $this->merchantReference;
    }

    /**
     * @param string $merchantReference
     * @return CreateRequest
     */
    public function setMerchantReference($merchantReference)
    {
        $this->merchantReference = $merchantReference;

        return $this;
    }

    /**
     * Getter.
     *
     * @return string
     */
    public function getMerchantApiKey()
    {
        return $this->merchantApiKey;
    }

    /**
     * @param string $merchantApiKey
     * @return CreateRequest
     */
    public function setMerchantApiKey($merchantApiKey)
    {
        $this->merchantApiKey = $merchantApiKey;

        return $this;
    }

    /**
     * Getter.
     *
     * @return string
     */
    public function getCountry()
    {
        return $this->country;
    }

    /**
     * @param string $country
     * @return CreateRequest
     */
    public function setCountry($country)
    {
        $this->country = $country;

        return $this;
    }

    /**
     * Getter.
     *
     * @return string
     */
    public function getCurrency()
    {
        return $this->currency;
    }

    /**
     * @param string $currency
     * @return CreateRequest
     */
    public function setCurrency($currency)
    {
        $this->currency = $currency;

        return $this;
    }

    /**
     * Getter.
     *
     * @return string
     */
    public function getLanguage()
    {
        return $this->language;
    }

    /**
     * @param string $language
     * @return CreateRequest
     */
    public function setLanguage($language)
    {
        $this->language = $language;

        return $this;
    }

    /**
     * Getter.
     *
     * @return string
     */
    public function getMerchantConfirmationUrl()
    {
        return $this->merchantConfirmationUrl;
    }

    /**
     * @param string $merchantConfirmationUrl
     * @return CreateRequest
     */
    public function setMerchantConfirmationUrl($merchantConfirmationUrl)
    {
        $this->merchantConfirmationUrl = $merchantConfirmationUrl;

        return $this;
    }

    /**
     * Getter.
     *
     * @return string
     */
    public function getMerchantTermsUrl()
    {
        return $this->merchantTermsUrl;
    }

    /**
     * @param string $merchantTermsUrl
     * @return CreateRequest
     */
    public function setMerchantTermsUrl($merchantTermsUrl)
    {
        $this->merchantTermsUrl = $merchantTermsUrl;

        return $this;
    }

    /**
     * Getter.
     *
     * @return \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[]
     */
    public function getOrderItems()
    {
        return $this->orderItems;
    }

    /**
     * @param \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[] $orderItems
     * @return CreateRequest
     */
    public function setOrderItems($orderItems)
    {
        $this->orderItems = $orderItems;

        return $this;
    }

    /**
     * Getter.
     *
     * @return string
     */
    public function getMerchantCheckoutStatusPushUrl()
    {
        return $this->merchantCheckoutStatusPushUrl;
    }

    /**
     * @param string $merchantCheckoutStatusPushUrl
     * @return CreateRequest
     */
    public function setMerchantCheckoutStatusPushUrl($merchantCheckoutStatusPushUrl)
    {
        if (self::TEST_LOCAL_URL && $merchantCheckoutStatusPushUrl) {
            $merchantCheckoutStatusPushUrl = $this->httpToHttps((string)$merchantCheckoutStatusPushUrl);
        }
        $this->merchantCheckoutStatusPushUrl = $merchantCheckoutStatusPushUrl;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getMerchantSavedCreditCardPushUrl()
    {
        return $this->merchantSavedCreditCardPushUrl;
    }

    /**
     * @inheritDoc
     */
    public function setMerchantSavedCreditCardPushUrl($value)
    {
        $this->merchantSavedCreditCardPushUrl = $value;
    }

    /**
     * Getter.
     *
     * @return string
     */
    public function getMerchantOrderManagementStatusPushUrl()
    {
        return $this->merchantOrderManagementStatusPushUrl;
    }

    /**
     * @param string $value
     * @return CreateRequest
     */
    public function setMerchantOrderManagementStatusPushUrl($value)
    {
        if (self::TEST_LOCAL_URL && $value) {
            $value = $this->httpToHttps((string)$value);
        }
        $this->merchantOrderManagementStatusPushUrl = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getMerchantNotificationUrl()
    {
        return $this->merchantNotificationUrl;
    }

    /**
     * @inheritDoc
     */
    public function setMerchantNotificationUrl($value)
    {
        if (self::TEST_LOCAL_URL && $value) {
            $value = $this->httpToHttps((string)$value);
        }
        $this->merchantNotificationUrl = $value;

        return $this;
    }

    /**
     * Getter.
     *
     * @return string
     */
    public function getMerchantOrderValidationUrl()
    {
        return $this->merchantOrderValidationUrl;
    }

    /**
     * @param string $merchantOrderValidationUrl
     * @return CreateRequest
     */
    public function setMerchantOrderValidationUrl($merchantOrderValidationUrl)
    {
        if (self::TEST_LOCAL_URL && $merchantOrderValidationUrl) {
            $merchantOrderValidationUrl = $this->httpToHttps((string)$merchantOrderValidationUrl);
        }
        $this->merchantOrderValidationUrl = $merchantOrderValidationUrl;

        return $this;
    }

    /**
     * Getter.
     *
     * @return string
     */
    public function getMerchantOrderAvailableShippingMethodsUrl()
    {
        return $this->merchantOrderAvailableShippingMethodsUrl;
    }

    /**
     * @param string $merchantOrderAvailableShippingMethodsUrl
     * @return CreateRequest
     */
    public function setMerchantOrderAvailableShippingMethodsUrl($merchantOrderAvailableShippingMethodsUrl)
    {
        if (self::TEST_LOCAL_URL && $merchantOrderAvailableShippingMethodsUrl) {
            $merchantOrderAvailableShippingMethodsUrl = $this->httpToHttps(
                (string)$merchantOrderAvailableShippingMethodsUrl
            );
        }
        $this->merchantOrderAvailableShippingMethodsUrl = $merchantOrderAvailableShippingMethodsUrl;

        return $this;
    }

    /**
     * Getter.
     *
     * @return string
     */
    public function getMerchantIntegrityPolicyUrl()
    {
        return $this->merchantIntegrityPolicyUrl;
    }

    /**
     * @param string $merchantIntegrityPolicyUrl
     * @return CreateRequest
     */
    public function setMerchantIntegrityPolicyUrl($merchantIntegrityPolicyUrl)
    {
        if (self::TEST_LOCAL_URL && $merchantIntegrityPolicyUrl) {
            $merchantIntegrityPolicyUrl = $this->httpToHttps((string)$merchantIntegrityPolicyUrl);
        }
        $this->merchantIntegrityPolicyUrl = $merchantIntegrityPolicyUrl;

        return $this;
    }

    /**
     * Getter.
     *
     * @return string
     */
    public function getBackgroundColor()
    {
        return $this->backgroundColor;
    }

    /**
     * @param string $backgroundColor
     * @return CreateRequest
     */
    public function setBackgroundColor($backgroundColor)
    {
        $this->backgroundColor = $backgroundColor;

        return $this;
    }

    /**
     * Getter.
     *
     * @return string
     */
    public function getPrimaryColor()
    {
        return $this->primaryColor;
    }

    /**
     * @param string $primaryColor
     * @return CreateRequest
     */
    public function setPrimaryColor($primaryColor)
    {
        $this->primaryColor = $primaryColor;

        return $this;
    }

    /**
     * Getter.
     *
     * @return string
     */
    public function getCallToActionColor()
    {
        return $this->callToActionColor;
    }

    /**
     * @param string $callToActionColor
     * @return CreateRequest
     */
    public function setCallToActionColor($callToActionColor)
    {
        $this->callToActionColor = $callToActionColor;

        return $this;
    }

    /**
     * Getter.
     *
     * @return string
     */
    public function getCallToActionHoverColor()
    {
        return $this->callToActionHoverColor;
    }

    /**
     * @param string $callToActionHoverColor
     * @return CreateRequest
     */
    public function setCallToActionHoverColor($callToActionHoverColor)
    {
        $this->callToActionHoverColor = $callToActionHoverColor;

        return $this;
    }

    /**
     * Getter.
     *
     * @return int
     */
    public function getCornerRadius()
    {
        return $this->cornerRadius;
    }

    /**
     * @param int $cornerRadius
     * @return CreateRequest
     */
    public function setCornerRadius($cornerRadius)
    {
        $this->cornerRadius = $cornerRadius;

        return $this;
    }

    /**
     * Getter.
     *
     * @return int
     */
    public function getButtonCornerRadius()
    {
        return $this->buttonCornerRadius;
    }

    /**
     * @param int $buttonCornerRadius
     * @return CreateRequest
     */
    public function setButtonCornerRadius($buttonCornerRadius)
    {
        $this->buttonCornerRadius = $buttonCornerRadius;

        return $this;
    }

    /**
     * Getter.
     *
     * @return \Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface
     */
    public function getCustomerInformation()
    {
        return $this->customerInformation;
    }

    /**
     * @param \Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface $customerInformation
     * @return CreateRequest
     */
    public function setCustomerInformation($customerInformation)
    {
        $this->customerInformation = $customerInformation;

        return $this;
    }

    /**
     * Getter.
     *
     * @return string
     */
    public function getAvailableShippingMethods()
    {
        return $this->availableShippingMethods;
    }

    /**
     * @param string $availableShippingMethods
     * @return CreateRequest
     */
    public function setAvailableShippingMethods($availableShippingMethods)
    {
        $this->availableShippingMethods = $availableShippingMethods;

        return $this;
    }

    /**
     * Getter.
     *
     * @return \Qliro\QliroOne\Api\Data\QliroOrderShippingConfigInterface
     */
    public function getShippingConfiguration()
    {
        return $this->shippingConfiguration;
    }

    /**
     * @param \Qliro\QliroOne\Api\Data\QliroOrderShippingConfigInterface $shippingConfiguration
     * @return CreateRequest
     */
    public function setShippingConfiguration($shippingConfiguration)
    {
        $this->shippingConfiguration = $shippingConfiguration;

        return $this;
    }

    /**
     * Getter.
     *
     * @return int
     */
    public function getMinimumCustomerAge()
    {
        return $this->minimumCustomerAge;
    }

    /**
     * @param int $minimumCustomerAge
     * @return CreateRequest
     */
    public function setMinimumCustomerAge($minimumCustomerAge)
    {
        $this->minimumCustomerAge = $minimumCustomerAge;

        return $this;
    }

    /**
     * Getter.
     *
     * @return bool
     */
    public function getAskForNewsletterSignup()
    {
        return $this->askForNewsletterSignup;
    }

    /**
     * @param bool $askForNewsletterSignup
     * @return CreateRequest
     */
    public function setAskForNewsletterSignup($askForNewsletterSignup)
    {
        $this->askForNewsletterSignup = $askForNewsletterSignup;

        return $this;
    }

    /**
     * @return bool
     */
    public function getAskForNewsletterSignupChecked()
    {
        return $this->askForNewsletterSignupChecked;
    }

    /**
     * @param bool $askForNewsletterSignup
     * @return CreateRequest
     */
    public function setAskForNewsletterSignupChecked($askForNewsletterSignupChecked)
    {
        $this->askForNewsletterSignupChecked = $askForNewsletterSignupChecked;

        return $this;
    }

    /**
     * Getter.
     *
     * @return bool
     */
    public function getRequireIdentityVerification()
    {
        return $this->requireIdentityVerification;
    }

    /**
     * @param bool $requireIdentityVerification
     * @return CreateRequest
     */
    public function setRequireIdentityVerification($requireIdentityVerification)
    {
        $this->requireIdentityVerification = $requireIdentityVerification;

        return $this;
    }

    /**
     * Replace http with https in URL
     *
     * @param string $url
     * @return string
     */
    private function httpToHttps(string $url): string
    {
        return str_replace('http:', 'https:', (string)$url);
    }
}
