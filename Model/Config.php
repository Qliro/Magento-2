<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Payment\Model\Method\Adapter;
use Magento\Framework\App\Config\ScopeConfigInterface as ScopeConfig;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;

class Config
{
    const string QLIROONE_ACTIVE = 'active';
    const string QLIROONE_TITLE = 'title';
    const string QLIROONE_DEBUG = 'debug';
    const string QLIROONE_EAGER_CHECKOUT_REFRESH = 'eager_checkout_refresh';

    const string QLIROONE_COUNTRY_SELECTOR = 'api/country_selector';
    const string QLIROONE_GEOIP = 'api/geoip';
    const string QLIROONE_LOGGING_LEVEL = 'api/logging';
    const string QLIROONE_ORDER_STATUS = 'api/order_status';
    const string QLIROONE_ALLOW_SPECIFIC = 'api/allowspecific';
    const string QLIROONE_COUNTRIES = 'api/shipping_countries';
    const string QLIROONE_CAPTURE_ON_SHIPMENT = 'api/capture_on_shipment';
    const string QLIROONE_CAPTURE_ON_INVOICE = 'api/capture_on_invoice';
    const string QLIROONE_NEWSLETTER_SIGNUP = 'api/newsletter_signup';
    const string QLIROONE_NEWSLETTER_SIGNUP_PRECHECKED = 'api/newsletter_signup_prechecked';
    const string QLIROONE_REQUIRE_IDENTITY_VERIFICATION = 'api/require_identity_verification';
    const string QLIROONE_MINIMUM_CUSTOMER_AGE = 'api/minimum_customer_age';
    const string QLIROONE_B2B_CHECKOUT_ONLY = 'api/b2b_checkout_only';
    const string QLIROONE_SHOW_AS_PAYMENT_METHOD = 'api/show_as_payment_method';

    const string QLIROONE_API_TYPE = 'qliro_api/type';
    const string QLIROONE_MERCHANT_API_KEY = 'qliro_api/merchant_api_key';
    const string QLIROONE_MERCHANT_API_SECRET = 'qliro_api/merchant_api_secret';
    const string QLIROONE_PRESET_ADDRESS = 'qliro_api/preset_address';

    const string QLIROONE_STYLING_BACKGROUND = 'styling/background_color';
    const string QLIROONE_STYLING_PRIMARY = 'styling/primary_color';
    const string QLIROONE_STYLING_CALL_TO_ACTION = 'styling/call_to_action_color';
    const string QLIROONE_STYLING_HOVER = 'styling/call_to_action_hover_color';
    const string QLIROONE_STYLING_RADIUS = 'styling/corner_radius';
    const string QLIROONE_STYLING_BUTTON_CORNER = 'styling/button_corner_radius';

    const string QLIROONE_FEE_MERCHANT_REFERENCE = 'merchant/fee_merchant_reference';
    const string QLIROONE_USE_INCREMENT_ID_AS_REFERENCE = 'merchant/use_increment_id_as_reference';
    const string QLIROONE_TERMS_URL = 'merchant/terms_url';
    const string QLIROONE_INTEGRITY_POLICY_URL = 'merchant/integrity_policy_url';

    const string QLIROONE_XDEBUG_SESSION_FLAG_NAME = 'callback/xdebug_session_flag_name';
    const string QLIROONE_REDIRECT_CALLBACKS = 'callback/redirect_callbacks';
    const string QLIROONE_CALLBACK_URI = 'callback/callback_uri';
    const string QLIROONE_ENABLE_HTTP_AUTH = 'callback/enable_http_auth';
    const string QLIROONE_HTTP_AUTH_USERNAME = 'callback/http_auth_username';
    const string QLIROONE_HTTP_AUTH_PASSWORD = 'callback/http_auth_password';

    const string QLIROONE_ADDITIONAL_INFO_REFERENCE = 'qliro_reference';
    const string QLIROONE_ADDITIONAL_INFO_QLIRO_ORDER_ID = 'qliro_order_id';
    const string QLIROONE_ADDITIONAL_INFO_PAYMENT_METHOD_CODE = 'qliro_payment_method_code';
    const string QLIROONE_ADDITIONAL_INFO_PAYMENT_METHOD_NAME = 'qliro_payment_method_name';
    const string QLIROONE_ADDITIONAL_INFO_SHIPPING_PROPERTIES = 'qliro_payment_shipping_properties';

    const string CONFIG_FEE_AMOUNT = 'fee';
    const string CONFIG_FEE_TITLE = 'description';

    const string TOTALS_FEE_CODE = 'qliroone_fee';
    const string TOTALS_FEE_CODE_TAX = 'qliroone_fee_tax';
    const string TOTALS_BASE_FEE_CODE = 'base_qliroone_fee';
    const string TOTALS_BASE_FEE_CODE_TAX = 'base_qliroone_fee_tax';

    const string QLIROONE_UNIFAUN_ENABLED = 'unifaun/enable';
    const string QLIROONE_UNIFAUN_SHIPPING_ENABLED = 'carriers/qlirounifaun/active';
    const string QLIROONE_UNIFAUN_CHECKOUT_ID = 'unifaun/checkout_id';
    const string QLIROONE_UNIFAUN_PARAMETERS = 'unifaun/parameters';

    const string QLIROONE_INGRID_ENABLED = 'ingrid/enable';
    const string QLIROONE_INGRID_SHIPPING_ENABLED = 'carriers/qliroingrid/active';

    const string QLIROONE_RECURRING_ENABLE = 'recurring_payments/enable';
    const string QLIROONE_RECURRING_FREQUENCY_OPTIONS = 'recurring_payments/frequency_options';

    const string QLIROONE_LOG_RETENTION_DAYS = 'debugging/log_retention_days';

    const string QLIROONE_DEV_AUTO_CALLBACK = 'dev/auto_callback';

    /**
     * Payment Fee tax class
     */
    const string XML_PATH_TAX_CLASS = 'tax/classes/qliroone_fee_tax_class';

    /**
     * @todo Improvement for proper module. Make use of this setting, it is not at the moment
     *
     * Shopping cart display settings
     */
    const string XML_PATH_PRICE_DISPLAY_CART_PAYMENT_FEE = 'tax/cart_display/qliroone_fee';

    /**
     * @todo Improvement for proper module. Make use of this setting, it is not at the moment
     *
     * Sales display settings
     */
    const string XML_PATH_PRICE_DISPLAY_SALES_PAYMENT_FEE = 'tax/sales_display/qliroone_fee';

    /**
     * tax calculation for payment fee
     */
    const string CONFIG_XML_PATH_PAYMENT_FEE_INCLUDES_TAX = 'tax/calculation/qliroone_fee_includes_tax';

    /**
     * Class constructor
     *
     * @param Adapter                             $adapter
     * @param ScopeConfig                         $config
     * @param Json                                $json
     * @param DirectoryHelper                     $directoryHelper
     * @param CountryCollectionFactory            $countryCollectionFactory
     */
    public function __construct(
        private readonly Adapter                  $adapter,
        protected readonly ScopeConfig            $config,
        private readonly Json                     $json,
        private readonly DirectoryHelper          $directoryHelper,
        private readonly CountryCollectionFactory $countryCollectionFactory
    ) {
    }

    /**
     * Check if the payment method is active
     *
     * @return bool
     */
    public function isActive(): bool
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_ACTIVE);
    }

    /**
     * Check if country selector should be used
     *
     * @return bool
     */
    public function isUseCountrySelector(): bool
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_COUNTRY_SELECTOR);
    }

    /**
     * Check if the GeoIP capability should be used
     *
     * @return bool
     */
    public function isUseGeoIp(): bool
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_GEOIP);
    }

    /**
     * Check whether debug mode is on
     *
     * @return bool
     */
    public function isDebugMode(): bool
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_DEBUG);
    }

    /**
     * Whether the local-development callback poller is enabled.
     *
     * This is a developer-only convenience flag (see PollCallbackCommand) and must never
     * be enabled on production. The poller itself additionally requires developer app mode.
     *
     * @return bool
     */
    public function isDevAutoCallbackEnabled(): bool
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_DEV_AUTO_CALLBACK);
    }

    /**
     * Whether the reserved Magento order increment_id should be used as the Qliro
     * merchant reference instead of a random hash.
     *
     * When enabled, settlement reports (e.g. PayPal) match Magento orders directly
     * by increment_id. Side effect: the increment_id is reserved at checkout init,
     * not at order placement, so abandoned checkouts leave gaps in the Magento
     * order sequence.
     *
     * @return bool
     */
    public function useIncrementIdAsReference(): bool
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_USE_INCREMENT_ID_AS_REFERENCE);
    }

    /**
     * Check whether an Eager Checkout Refresh mode is on
     *
     * @return bool
     */
    public function isEagerCheckoutRefresh(): bool
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_EAGER_CHECKOUT_REFRESH);
    }

    /**
     * Check whether callbacks should be routed through a public server
     *
     * @return bool
     */
    public function redirectCallbacks(): bool
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_REDIRECT_CALLBACKS);
    }

    /**
     * Get url for callback server
     *
     * @return string
     */
    public function getCallbackUri(): string
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_CALLBACK_URI);
    }

    /**
     * Get payment method title
     *
     * @return string
     */
    public function getTitle(): string
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_TITLE);
    }

    /**
     * Get the status order will end up on successful payment
     *
     * @return string
     */
    public function getOrderStatus(): string
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_ORDER_STATUS);
    }

    /**
     * @return bool
     */
    public function getAllowSpecific(): bool
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_ALLOW_SPECIFIC);
    }

    /**
     * @return string
     */
    public function getSpecificCountries(): string
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_COUNTRIES);
    }

    /**
     * @param int|string|null $storeId
     * @return bool
     */
    public function shouldCaptureOnShipment(int|string|null $storeId = null): bool
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_CAPTURE_ON_SHIPMENT, $storeId);
    }

    /**
     * @param int|string|null $storeId
     * @return bool
     */
    public function shouldCaptureOnInvoice(int|string|null $storeId = null): bool
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_CAPTURE_ON_INVOICE, $storeId);
    }

    /**
     * @param int|string|null $storeId
     * @return bool
     */
    public function shouldAskForNewsletterSignup(int|string|null $storeId = null): bool
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_NEWSLETTER_SIGNUP, $storeId);
    }

    /**
     * @param int|string|null $storeId
     * @return bool
     */
    public function askForNewsletterSignupChecked(int|string|null $storeId = null): bool
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_NEWSLETTER_SIGNUP_PRECHECKED, $storeId);
    }

    /**
     * @param int|string|null $storeId
     * @return bool
     */
    public function requireIdentityVerification(int|string|null $storeId = null): bool
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_REQUIRE_IDENTITY_VERIFICATION, $storeId);
    }

    /**
     * Get API type can be either "sandbox" or "prod"
     *
     * @param int|string|null $storeId
     * @return string
     */
    public function getApiType(int|string|null $storeId = null): string
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_API_TYPE, $storeId);
    }

    /**
     * @param int|string|null $storeId
     * @return string
     */
    public function getMerchantApiKey(int|string|null $storeId = null): string
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_MERCHANT_API_KEY, $storeId);
    }

    /**
     * @param int|string|null $storeId
     * @return string
     */
    public function getMerchantApiSecret(int|string|null $storeId = null): string
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_MERCHANT_API_SECRET, $storeId);
    }

    /**
     * @return bool
     */
    public function presetAddress(): bool
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_PRESET_ADDRESS);
    }

    /**
     * @return string|null
     */
    public function getStylingBackgroundColor(): ?string
    {
        return $this->checkHexColor($this->adapter->getConfigData(self::QLIROONE_STYLING_BACKGROUND));
    }

    /**
     * @return string|null
     */
    public function getStylingPrimaryColor(): ?string
    {
        return $this->checkHexColor($this->adapter->getConfigData(self::QLIROONE_STYLING_PRIMARY));
    }

    /**
     * @return string|null
     */
    public function getStylingCallToActionColor(): ?string
    {
        return $this->checkHexColor($this->adapter->getConfigData(self::QLIROONE_STYLING_CALL_TO_ACTION));
    }

    /**
     * @return string|null
     */
    public function getStylingHoverColor(): ?string
    {
        return $this->checkHexColor($this->adapter->getConfigData(self::QLIROONE_STYLING_HOVER));
    }

    /**
     * @return int
     */
    public function getStylingRadius(): int
    {
        return (int)$this->adapter->getConfigData(self::QLIROONE_STYLING_RADIUS);
    }

    /**
     * @return int
     */
    public function getStylingButtonCornerRadius(): int
    {
        return (int)$this->adapter->getConfigData(self::QLIROONE_STYLING_BUTTON_CORNER);
    }

    /**
     * @return string
     */
    public function getFeeMerchantReference(): string
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_FEE_MERCHANT_REFERENCE);
    }

    /**
     * @return string|null
     */
    public function getTermsUrl(): ?string
    {
        $value = $this->adapter->getConfigData(self::QLIROONE_TERMS_URL);

        return $value ? (string)$value : null;
    }

    /**
     * @return string|null
     */
    public function getIntegrityPolicyUrl(): ?string
    {
        $value = $this->adapter->getConfigData(self::QLIROONE_INTEGRITY_POLICY_URL);

        return $value ? (string)$value : null;
    }

    /**
     * Check if HTTP Auth for callbacks is enabled
     *
     * @return bool
     */
    public function isHttpAuthEnabled(): bool
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_ENABLE_HTTP_AUTH);
    }

    /**
     * Get an HTTP Auth username for callbacks
     *
     * @return string
     */
    public function getCallbackHttpAuthUsername(): string
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_HTTP_AUTH_USERNAME);
    }

    /**
     * Get an HTTP Auth password for callbacks
     *
     * @return string
     */
    public function getCallbackHttpAuthPassword(): string
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_HTTP_AUTH_PASSWORD);
    }

    /**
     * Get XDebug session flag name for callbacks
     *
     * @return string
     */
    public function getCallbackXdebugSessionFlagName(): string
    {
        if (!$this->isDebugMode()) {
            return '';
        }
        return (string)$this->adapter->getConfigData(self::QLIROONE_XDEBUG_SESSION_FLAG_NAME);
    }

    /**
     * Dummy config for payment method compatibility
     *
     * @return bool
     */
    public function shouldUpdateQuoteBilling(): bool
    {
        return true;
    }

    /**
     * Dummy config for payment method compatibility
     *
     * @return bool
     */
    public function shouldUpdateQuoteShipping(): bool
    {
        return true;
    }

    /**
     * Check if the value a proper HEX color code, return null otherwise
     *
     * @param mixed $value
     * @return string|null
     */
    private function checkHexColor(mixed $value): ?string
    {
        return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim((string)$value)) ? trim((string)$value) : null;
    }

    /**
     * Get TaxClass for Fee
     *
     * @param Store|int|null $store
     * @return string|null
     */
    public function getFeeTaxClass(mixed $store = null): ?string
    {
        return $this->config->getValue(
            self::XML_PATH_TAX_CLASS,
            ScopeInterface::SCOPE_STORE,
            $store
        );
    }

    /**
     * Check the ability to display prices including tax for payment fee in shopping cart
     *
     * @param Store|int|null $store
     * @return bool
     */
    public function displayCartPaymentFeeIncludeTaxPrice(mixed $store = null): bool
    {
        $configValue = $this->config->getValue(
            self::XML_PATH_PRICE_DISPLAY_CART_PAYMENT_FEE,
            ScopeInterface::SCOPE_STORE,
            $store
        );
        return $configValue == \Magento\Tax\Model\Config::DISPLAY_TYPE_BOTH ||
            $configValue == \Magento\Tax\Model\Config::DISPLAY_TYPE_INCLUDING_TAX;
    }

    /**
     * Check the ability to display prices excluding tax for payment fee in shopping cart
     *
     * @param Store|int|null $store
     * @return bool
     */
    public function displayCartPaymentFeeExcludeTaxPrice(mixed $store = null): bool
    {
        $configValue = $this->config->getValue(
            self::XML_PATH_PRICE_DISPLAY_CART_PAYMENT_FEE,
            ScopeInterface::SCOPE_STORE,
            $store
        );
        return $configValue == \Magento\Tax\Model\Config::DISPLAY_TYPE_EXCLUDING_TAX;
    }

    /**
     * Check ability to display both prices for payment fee in shopping cart
     *
     * @param Store|int|null $store
     * @return bool
     */
    public function displayCartPaymentFeeBothPrices(mixed $store = null): bool
    {
        $configValue = $this->config->getValue(
            self::XML_PATH_PRICE_DISPLAY_CART_PAYMENT_FEE,
            ScopeInterface::SCOPE_STORE,
            $store
        );
        return $configValue == \Magento\Tax\Model\Config::DISPLAY_TYPE_BOTH;
    }

    /**
     * Check the ability to display prices including tax for payment fee in backend sales
     *
     * @param Store|int|null $store
     * @return bool
     */
    public function displaySalesPaymentFeeIncludeTaxPrice(mixed $store = null): bool
    {
        $configValue = $this->config->getValue(
            self::XML_PATH_PRICE_DISPLAY_SALES_PAYMENT_FEE,
            ScopeInterface::SCOPE_STORE,
            $store
        );
        return $configValue == \Magento\Tax\Model\Config::DISPLAY_TYPE_BOTH ||
            $configValue == \Magento\Tax\Model\Config::DISPLAY_TYPE_INCLUDING_TAX;
    }

    /**
     * Check the ability to display prices excluding tax for payment fee in backend sales
     *
     * @param Store|int|null $store
     * @return bool
     */
    public function displaySalesPaymentFeeExcludeTaxPrice(mixed $store = null): bool
    {
        $configValue = $this->config->getValue(
            self::XML_PATH_PRICE_DISPLAY_SALES_PAYMENT_FEE,
            ScopeInterface::SCOPE_STORE,
            $store
        );
        return $configValue == \Magento\Tax\Model\Config::DISPLAY_TYPE_EXCLUDING_TAX;
    }

    /**
     * Check the ability to display both prices for payment fee in backend sales
     *
     * @param Store|int|null $store
     * @return bool
     */
    public function displaySalesPaymentFeeBothPrices(mixed $store = null): bool
    {
        $configValue = $this->config->getValue(
            self::XML_PATH_PRICE_DISPLAY_SALES_PAYMENT_FEE,
            ScopeInterface::SCOPE_STORE,
            $store
        );
        return $configValue == \Magento\Tax\Model\Config::DISPLAY_TYPE_BOTH;
    }

    /**
     * Check if shipping prices include tax
     *
     * @param Store|int|null $store
     * @return bool
     */
    public function paymentFeeIncludesTax(mixed $store = null): bool
    {
        $configValue = $this->config->getValue(
            self::CONFIG_XML_PATH_PAYMENT_FEE_INCLUDES_TAX,
            ScopeInterface::SCOPE_STORE,
            $store
        );
        return (bool)$configValue;
    }

    /**
     * @param int|string|null $storeId
     * @return bool
     */
    public function isUnifaunEnabled(int|string|null $storeId = null): bool
    {
        if (!$this->adapter->getConfigData(self::QLIROONE_UNIFAUN_ENABLED, $storeId)) {
            return false;
        }

        if (!$this->config->getValue(self::QLIROONE_UNIFAUN_SHIPPING_ENABLED, ScopeInterface::SCOPE_STORE, $storeId)) {
            return false;
        }

        return true;
    }

    /**
     * @param int|string|null $storeId
     * @return string
     */
    public function getUnifaunCheckoutId(int|string|null $storeId = null): string
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_UNIFAUN_CHECKOUT_ID, $storeId);
    }

    /**
     * @param int|string|null $storeId
     * @return array
     */
    public function getUnifaunParameters(int|string|null $storeId = null): array
    {
        $str = (string)$this->adapter->getConfigData(self::QLIROONE_UNIFAUN_PARAMETERS, $storeId);
        if ($str) {
            return $this->json->unserialize($str);
        }

        return [];
    }

    /**
     * @param int|string|null $storeId
     * @return bool
     */
    public function isIngridEnabled(int|string|null $storeId = null): bool
    {
        if (!$this->adapter->getConfigData(self::QLIROONE_INGRID_ENABLED, $storeId)) {
            return false;
        }

        if (!$this->config->getValue(self::QLIROONE_INGRID_SHIPPING_ENABLED, ScopeInterface::SCOPE_STORE, $storeId)) {
            return false;
        }

        return true;
    }

    /**
     * @param int|string|null $storeId
     * @return int
     */
    public function getMinimumCustomerAge(int|string|null $storeId = null): int
    {
        return (int)$this->adapter->getConfigData(self::QLIROONE_MINIMUM_CUSTOMER_AGE, $storeId);
    }

    /**
     * Check if only B2B checkout is enabled for companies
     *
     * @param int|string|null $storeId
     * @return bool
     */
    public function isB2BCheckoutOnlyEnabled(int|string|null $storeId = null): bool
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_B2B_CHECKOUT_ONLY, $storeId);
    }

    /**
     * Check if qliro set to be shown as a payment method
     *
     * @param int|string|null $storeId
     * @return bool
     */
    public function getShowAsPaymentMethod(int|string|null $storeId = null): bool
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_SHOW_AS_PAYMENT_METHOD, $storeId);
    }

    /**
     * @param int|string|null $storeId
     * @return bool
     */
    public function isUseRecurring(int|string|null $storeId = null): bool
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_RECURRING_ENABLE, $storeId);
    }

    /**
     * @param int|string|null $storeId
     * @return string
     */
    public function getRecurringFrequencyOptions(int|string|null $storeId = null): string
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_RECURRING_FREQUENCY_OPTIONS, $storeId);
    }

    /**
     * Gets available countries depending on current config:
     * - if "allow specific" is enabled, returns the list of countries from "specific countries" config
     * - otherwise, returns general list of allowed countries
     *
     * @param int|string|null $storeId
     * @return array Option format: ['value' => 'SE', 'label' => 'Sweden']
     */
    public function getAvailableCountries(int|string|null $storeId = null): array
    {
        if (!$this->getAllowSpecific()) {
            return $this->directoryHelper->getCountryCollection($storeId)->toOptionArray(false);
        }
        $countryCollection = $this->countryCollectionFactory->create();
        $countryIds = explode(',', $this->getSpecificCountries());

        $countryCollection->addFieldToFilter('country_id', ['in' => $countryIds]);
        return $countryCollection->toOptionArray(false);
    }

    /**
     * Get default country
     *
     * @param int|string|null $storeId
     * @return string
     */
    public function getDefaultCountry(int|string|null $storeId = null): string
    {
        return $this->directoryHelper->getDefaultCountry($storeId);
    }

    /**
     * Number of days to retain log records and log file entries before cleanup.
     *
     * @param int|string|null $storeId
     * @return int
     */
    public function getLogRetentionDays(int|string|null $storeId = null): int
    {
        return (int) $this->config->getValue(
            'payment/qliroone/' . self::QLIROONE_LOG_RETENTION_DAYS,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
