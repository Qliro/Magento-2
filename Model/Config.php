<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
namespace Qliro\QliroOne\Model;

use Magento\Framework\Serialize\Serializer\Json;
use Magento\Payment\Model\Method\Adapter;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Directory\Model\ResourceModel\Country\CollectionFactory as CountryCollectionFactory;
use Magento\Store\Model\ScopeInterface;
use Qliro\QliroOne\Model\Config\Source\PaymentMethodRenderMode;

class Config
{
    const QLIROONE_ACTIVE = 'active';
    const QLIROONE_TITLE = 'title';
    const QLIROONE_DEBUG = 'debug';
    const QLIROONE_EAGER_CHECKOUT_REFRESH = 'eager_checkout_refresh';

    const QLIROONE_COUNTRY_SELECTOR = 'api/country_selector';
    const QLIROONE_GEOIP = 'api/geoip';
    const QLIROONE_LOGGING_LEVEL = 'api/logging';
    const QLIROONE_ORDER_STATUS = 'api/order_status';
    const QLIROONE_ALLOW_SPECIFIC = 'api/allowspecific';
    const QLIROONE_COUNTRIES = 'api/shipping_countries';
    const QLIROONE_CAPTURE_ON_SHIPMENT = 'api/capture_on_shipment';
    const QLIROONE_CAPTURE_ON_INVOICE = 'api/capture_on_invoice';
    const QLIROONE_NEWSLETTER_SIGNUP = 'api/newsletter_signup';
    const QLIROONE_NEWSLETTER_SIGNUP_PRECHECKED = 'api/newsletter_signup_prechecked';
    const QLIROONE_REQUIRE_IDENTITY_VERIFICATION = 'api/require_identity_verification';
    const QLIROONE_MINIMUM_CUSTOMER_AGE = 'api/minimum_customer_age';
    const QLIROONE_B2B_CHECKOUT_ONLY = 'api/b2b_checkout_only';
    const QLIROONE_SHOW_AS_PAYMENT_METHOD = 'api/show_as_payment_method';
    const QLIROONE_HIDE_NATIVE_SHIPPING_STEP = 'api/hide_native_shipping_step';
    const QLIROONE_PAYMENT_METHOD_RENDER_MODE = 'api/payment_method_render_mode';

    const QLIROONE_API_TYPE = 'qliro_api/type';
    const QLIROONE_MERCHANT_API_KEY = 'qliro_api/merchant_api_key';
    const QLIROONE_MERCHANT_API_SECRET = 'qliro_api/merchant_api_secret';
    const QLIROONE_PRESET_ADDRESS = 'qliro_api/preset_address';

    /**
     * Which pair of timeouts a call is made with: whether anybody is waiting for the answer
     *
     * It is the call that says so, not the client class it goes through. The same client serves
     * both: a checkout page fetch and the status push Qliro sends afterwards are one class, and
     * so are the admin order view and the capture behind a shipment.
     */
    const API_PROFILE_INTERACTIVE = 'interactive';
    const API_PROFILE_BACKGROUND = 'background';

    const QLIROONE_INTERACTIVE_CONNECT_TIMEOUT = 'timeouts/interactive_connect';
    const QLIROONE_INTERACTIVE_REQUEST_TIMEOUT = 'timeouts/interactive_request';
    const QLIROONE_BACKGROUND_CONNECT_TIMEOUT = 'timeouts/background_connect';
    const QLIROONE_BACKGROUND_REQUEST_TIMEOUT = 'timeouts/background_request';

    const DEFAULT_INTERACTIVE_CONNECT_TIMEOUT = 5;
    const DEFAULT_INTERACTIVE_REQUEST_TIMEOUT = 15;
    const DEFAULT_BACKGROUND_CONNECT_TIMEOUT = 5;
    const DEFAULT_BACKGROUND_REQUEST_TIMEOUT = 60;
    const MAX_API_TIMEOUT = 300;

    const QLIROONE_STYLING_BACKGROUND = 'styling/background_color';
    const QLIROONE_STYLING_PRIMARY = 'styling/primary_color';
    const QLIROONE_STYLING_CALL_TO_ACTION = 'styling/call_to_action_color';
    const QLIROONE_STYLING_HOVER = 'styling/call_to_action_hover_color';
    const QLIROONE_STYLING_RADIUS = 'styling/corner_radius';
    const QLIROONE_STYLING_BUTTON_CORNER = 'styling/button_corner_radius';

    const QLIROONE_FEE_MERCHANT_REFERENCE = 'merchant/fee_merchant_reference';
    const QLIROONE_USE_INCREMENT_ID_AS_REFERENCE = 'merchant/use_increment_id_as_reference';
    const QLIROONE_TERMS_URL = 'merchant/terms_url';
    const QLIROONE_INTEGRITY_POLICY_URL = 'merchant/integrity_policy_url';

    const XML_PATH_LOG_RETENTION_DAYS = 'payment/qliroone/debugging/log_retention_days';
    const FRESH_INSTALL_LOG_RETENTION_DAYS = 30;
    const MAX_LOG_RETENTION_DAYS = 36500;

    const QLIROONE_XDEBUG_SESSION_FLAG_NAME = 'callback/xdebug_session_flag_name';
    const QLIROONE_CALLBACK_TOKEN_LIFETIME_DAYS = 'callback/token_lifetime_days';
    const DEFAULT_CALLBACK_TOKEN_LIFETIME_DAYS = 1095;
    const MAX_CALLBACK_TOKEN_LIFETIME_DAYS = 1095;

    const QLIROONE_REDIRECT_CALLBACKS = 'callback/redirect_callbacks';
    const QLIROONE_CALLBACK_URI = 'callback/callback_uri';
    const QLIROONE_ENABLE_HTTP_AUTH = 'callback/enable_http_auth';
    const QLIROONE_HTTP_AUTH_USERNAME = 'callback/http_auth_username';
    const QLIROONE_HTTP_AUTH_PASSWORD = 'callback/http_auth_password';

    const QLIROONE_ADDITIONAL_INFO_REFERENCE = 'qliro_reference';
    const QLIROONE_ADDITIONAL_INFO_QLIRO_ORDER_ID = 'qliro_order_id';
    const QLIROONE_ADDITIONAL_INFO_PAYMENT_METHOD_CODE = 'qliro_payment_method_code';
    const QLIROONE_ADDITIONAL_INFO_PAYMENT_METHOD_NAME = 'qliro_payment_method_name';
    const QLIROONE_ADDITIONAL_INFO_SHIPPING_PROPERTIES = 'qliro_payment_shipping_properties';

    /**
     * Whether the reservation this order was placed against carries the VAT of the discount on its
     * discount line. Absent on every order placed before 1.7.18, whose reservation carries the
     * discount without VAT, and whose capture has to reproduce that or Qliro sees a changed line
     */
    const QLIROONE_ADDITIONAL_INFO_DISCOUNT_CARRIES_VAT = 'qliro_discount_carries_vat';

    /**
     * Whether the reservation this order was placed against carries the quote item id in the
     * merchant reference of its product lines. Absent on every order placed before 1.7.42, whose
     * reservation carries the bare sku, and whose capture has to reproduce that or Qliro sees a
     * changed line
     */
    const QLIROONE_ADDITIONAL_INFO_LINE_REFERENCE_CARRIES_ITEM_ID = 'qliro_line_reference_carries_item_id';

    const CONFIG_FEE_AMOUNT = 'fee';
    const CONFIG_FEE_TITLE = 'description';

    const TOTALS_FEE_CODE = 'qliroone_fee';
    const TOTALS_FEE_CODE_TAX = 'qliroone_fee_tax';
    const TOTALS_BASE_FEE_CODE = 'base_qliroone_fee';
    const TOTALS_BASE_FEE_CODE_TAX = 'base_qliroone_fee_tax';

    const QLIROONE_UNIFAUN_ENABLED = 'unifaun/enable';
    const QLIROONE_UNIFAUN_SHIPPING_ENABLED = 'carriers/qlirounifaun/active';
    const QLIROONE_UNIFAUN_CHECKOUT_ID = 'unifaun/checkout_id';
    const QLIROONE_UNIFAUN_PARAMETERS = 'unifaun/parameters';

    const QLIROONE_INGRID_ENABLED = 'ingrid/enable';
    const QLIROONE_INGRID_SHIPPING_ENABLED = 'carriers/qliroingrid/active';

    const QLIROONE_RECURRING_ENABLE = 'recurring_payments/enable';
    const QLIROONE_RECURRING_FREQUENCY_OPTIONS = 'recurring_payments/frequency_options';

    /**
     * Payment Fee tax class
     */
    const XML_PATH_TAX_CLASS = 'tax/classes/qliroone_fee_tax_class';

    /**
     * @todo Improvement for proper module. Make use of this setting, it is not at the moment
     *
     * Shopping cart display settings
     */
    const XML_PATH_PRICE_DISPLAY_CART_PAYMENT_FEE = 'tax/cart_display/qliroone_fee';

    /**
     * @todo Improvement for proper module. Make use of this setting, it is not at the moment
     *
     * Sales display settings
     */
    const XML_PATH_PRICE_DISPLAY_SALES_PAYMENT_FEE = 'tax/sales_display/qliroone_fee';

    /**
     * tax calculation for payment fee
     */
    const CONFIG_XML_PATH_PAYMENT_FEE_INCLUDES_TAX = 'tax/calculation/qliroone_fee_includes_tax';

    /**
     * @var Adapter
     */
    private $adapter;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $config;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var DirectoryHelper
     */
    private DirectoryHelper $directoryHelper;

    /**
     * @var CountryCollectionFactory
     */
    private CountryCollectionFactory $countryCollectionFactory;

    /**
     * Inject dependencies
     *
     * @param \Magento\Payment\Model\Method\Adapter $adapter
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $config
     * @param Json $json
     * @param DirectoryHelper $directoryHelper
     * @param CountryCollectionFactory $countryCollectionFactory
     */
    public function __construct(
        Adapter $adapter,
        ScopeConfigInterface $config,
        Json $json,
        DirectoryHelper $directoryHelper,
        CountryCollectionFactory $countryCollectionFactory
    ) {
        $this->adapter = $adapter;
        $this->config = $config;
        $this->json = $json;
        $this->directoryHelper = $directoryHelper;
        $this->countryCollectionFactory = $countryCollectionFactory;
    }

    /**
     * Check if the payment method is active
     *
     * @return bool
     */
    public function isActive()
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_ACTIVE);
    }

    /**
     * Check if country selector should be used
     *
     * @return boolean
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
    public function isUseGeoIp()
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_GEOIP);
    }

    /**
     * Check whether debug mode is on
     *
     * @return bool
     */
    public function isDebugMode()
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_DEBUG);
    }

    /**
     * Check whether an Eager Checkout Refresh mode is on
     *
     * @return bool
     */
    public function isEagerCheckoutRefresh()
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_EAGER_CHECKOUT_REFRESH);
    }

    /**
     * Check whether callbacks should be routed through a public server
     *
     * @return bool
     */
    public function redirectCallbacks()
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_REDIRECT_CALLBACKS);
    }

    /**
     * Get url for callback server
     *
     * @return string
     */
    public function getCallbackUri()
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_CALLBACK_URI);
    }

    /**
     * Get payment method title
     *
     * @return string
     */
    public function getTitle()
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_TITLE);
    }

    /**
     * Get the status order will end up on successful payment
     *
     * @return string
     */
    public function getOrderStatus()
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_ORDER_STATUS);
    }

    /**
     * @return bool
     */
    public function getAllowSpecific()
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_ALLOW_SPECIFIC);
    }

    /**
     * @return string
     */
    public function getSpecificCountries()
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_COUNTRIES);
    }

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function shouldCaptureOnShipment($storeId = null)
    {
        return (int)$this->adapter->getConfigData(self::QLIROONE_CAPTURE_ON_SHIPMENT, $storeId);
    }

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function shouldCaptureOnInvoice($storeId = null)
    {
        return (int)$this->adapter->getConfigData(self::QLIROONE_CAPTURE_ON_INVOICE, $storeId);
    }

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function shouldAskForNewsletterSignup($storeId = null)
    {
        return (int)$this->adapter->getConfigData(self::QLIROONE_NEWSLETTER_SIGNUP, $storeId);
    }

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function askForNewsletterSignupChecked($storeId = null)
    {
        return !!$this->adapter->getConfigData(self::QLIROONE_NEWSLETTER_SIGNUP_PRECHECKED, $storeId);
    }

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function requireIdentityVerification($storeId = null)
    {
        return (int)$this->adapter->getConfigData(self::QLIROONE_REQUIRE_IDENTITY_VERIFICATION, $storeId);
    }

    /**
     * Get API type (may be either "sandbox" or "prod"
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApiType($storeId = null)
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_API_TYPE, $storeId);
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getMerchantApiKey($storeId = null)
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_MERCHANT_API_KEY, $storeId);
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getMerchantApiSecret($storeId = null)
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_MERCHANT_API_SECRET, $storeId);
    }

    /**
     * Seconds to wait for the connection to Qliro to be established, per call
     *
     * @param string $profile
     * @param int|null $storeId
     * @return int
     */
    public function getApiConnectTimeout($profile = self::API_PROFILE_INTERACTIVE, $storeId = null): int
    {
        if ($profile === self::API_PROFILE_BACKGROUND) {
            return $this->readTimeout(
                self::QLIROONE_BACKGROUND_CONNECT_TIMEOUT,
                self::DEFAULT_BACKGROUND_CONNECT_TIMEOUT,
                $storeId
            );
        }

        return $this->readTimeout(
            self::QLIROONE_INTERACTIVE_CONNECT_TIMEOUT,
            self::DEFAULT_INTERACTIVE_CONNECT_TIMEOUT,
            $storeId
        );
    }

    /**
     * Seconds a whole call to Qliro may take, per call
     *
     * A call somebody is waiting for is cut short so they are answered, a call nobody is waiting
     * for is given time, because abandoning a capture Qliro has already accepted is worse than
     * waiting for its answer. That is the only reason the two are configured apart.
     *
     * @param string $profile
     * @param int|null $storeId
     * @return int
     */
    public function getApiRequestTimeout($profile = self::API_PROFILE_INTERACTIVE, $storeId = null): int
    {
        if ($profile === self::API_PROFILE_BACKGROUND) {
            return $this->readTimeout(
                self::QLIROONE_BACKGROUND_REQUEST_TIMEOUT,
                self::DEFAULT_BACKGROUND_REQUEST_TIMEOUT,
                $storeId
            );
        }

        return $this->readTimeout(
            self::QLIROONE_INTERACTIVE_REQUEST_TIMEOUT,
            self::DEFAULT_INTERACTIVE_REQUEST_TIMEOUT,
            $storeId
        );
    }

    /**
     * Read one timeout field, falling back to its default rather than to no timeout at all
     *
     * Guzzle reads 0 as "wait forever", which is the state this setting exists to end, so a field
     * left empty, cleared or filled with anything that is not a positive whole number of seconds
     * is the default, not an unlimited wait.
     *
     * @param string $path
     * @param int $default
     * @param int|null $storeId
     * @return int
     */
    private function readTimeout($path, $default, $storeId = null): int
    {
        $seconds = trim((string)$this->adapter->getConfigData($path, $storeId));

        if (!ctype_digit($seconds) || (int)$seconds < 1) {
            return $default;
        }

        return min((int)$seconds, self::MAX_API_TIMEOUT);
    }

    /**
     * @return bool
     */
    public function presetAddress()
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_PRESET_ADDRESS);
    }

    /**
     * @return string
     */
    public function getStylingBackgroundColor()
    {
        return $this->checkHexColor($this->adapter->getConfigData(self::QLIROONE_STYLING_BACKGROUND));
    }

    /**
     * @return string
     */
    public function getStylingPrimaryColor()
    {
        return $this->checkHexColor($this->adapter->getConfigData(self::QLIROONE_STYLING_PRIMARY));
    }

    /**
     * @return string
     */
    public function getStylingCallToActionColor()
    {
        return $this->checkHexColor($this->adapter->getConfigData(self::QLIROONE_STYLING_CALL_TO_ACTION));
    }

    /**
     * @return string
     */
    public function getStylingHoverColor()
    {
        return $this->checkHexColor($this->adapter->getConfigData(self::QLIROONE_STYLING_HOVER));
    }

    /**
     * @return int
     */
    public function getStylingRadius()
    {
        return (int)$this->adapter->getConfigData(self::QLIROONE_STYLING_RADIUS);
    }

    /**
     * @return int
     */
    public function getStylingButtonCurnerRadius()
    {
        return (int)$this->adapter->getConfigData(self::QLIROONE_STYLING_BUTTON_CORNER);
    }

    /**
     * @return string
     */
    public function getFeeMerchantReference()
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_FEE_MERCHANT_REFERENCE);
    }

    /**
     * Whether to use the reserved Magento order increment ID as the Qliro One
     * merchant reference instead of a randomly generated hash.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isUseIncrementIdAsReference($storeId = null)
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_USE_INCREMENT_ID_AS_REFERENCE, $storeId);
    }

    /**
     * @return string
     */
    public function getTermsUrl()
    {
        $value = $this->adapter->getConfigData(self::QLIROONE_TERMS_URL);

        return $value ? (string)$value : null;
    }

    /**
     * @return string
     */
    public function getIntegrityPolicyUrl()
    {
        $value = $this->adapter->getConfigData(self::QLIROONE_INTEGRITY_POLICY_URL);

        return $value ? (string)$value : null;
    }

    /**
     * Check if HTTP Auth for callbacks is enabled
     *
     * @return bool
     */
    public function isHttpAuthEnabled()
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_ENABLE_HTTP_AUTH);
    }

    /**
     * Get an HTTP Auth username for callbacks
     *
     * @return string
     */
    public function getCallbackHttpAuthUsername()
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_HTTP_AUTH_USERNAME);
    }

    /**
     * Get an HTTP Auth password for callbacks
     *
     * @return string
     */
    public function getCallbackHttpAuthPassword()
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_HTTP_AUTH_PASSWORD);
    }

    /**
     * How many days a newly minted callback token is valid for
     *
     * A token already registered with Qliro keeps the lifetime it was minted with, so changing
     * this cannot break a callback that is already out there.
     *
     * @return int
     */
    public function getCallbackTokenLifetimeDays(): int
    {
        $days = trim((string)$this->adapter->getConfigData(self::QLIROONE_CALLBACK_TOKEN_LIFETIME_DAYS));

        if (!ctype_digit($days) || (int)$days < 1) {
            return self::DEFAULT_CALLBACK_TOKEN_LIFETIME_DAYS;
        }

        return min((int)$days, self::MAX_CALLBACK_TOKEN_LIFETIME_DAYS);
    }

    /**
     * How many days of qliroone_log rows are kept, 0 keeps every row
     *
     * Read on the default scope, the only one the field lives on. A value that is no window at all
     * keeps every row: deleting a merchant's payloads cannot be undone, growth can.
     *
     * @return int
     */
    public function getLogRetentionDays(): int
    {
        $days = $this->parseLogRetentionDays($this->config->getValue(
            self::XML_PATH_LOG_RETENTION_DAYS,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        ));

        return $days ?? 0;
    }

    /**
     * The one reading of the retention field, for the config, the admin form and the console alike
     *
     * @param mixed $value
     * @return int|null Days, 0 keeps every row, null when the value is no window at all
     */
    public function parseLogRetentionDays($value): ?int
    {
        $days = trim((string)$value);

        if (!ctype_digit($days) || (int)$days > self::MAX_LOG_RETENTION_DAYS) {
            return null;
        }

        return (int)$days;
    }

    /**
     * Get XDebug session flag name for callbacks
     *
     * @return string
     */
    public function getCallbackXdebugSessionFlagName()
    {
        if (!$this->isDebugMode()) {
            return '';
        }
        return (string)$this->adapter->getConfigData(self::QLIROONE_XDEBUG_SESSION_FLAG_NAME);
    }

    /**
     * Dummy config for payment method compatibility
     *
     * @return boolean
     */
    public function shouldUpdateQuoteBilling()
    {
        return true;
    }

    /**
     * Dummy config for payment method compatibility
     *
     * @return boolean
     */
    public function shouldUpdateQuoteShipping()
    {
        return true;
    }

    /**
     * Check if the value a proper HEX color code, return null otherwise
     *
     * @param string $value
     * @return string|null
     */
    private function checkHexColor($value)
    {
        return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim($value)) ? trim($value) : null;
    }

    /**
     * Get TaxClass for Fee
     *
     * @param \Magento\Store\Model\Store|int|null $store
     * @return string|null
     */
    public function getFeeTaxClass($store = null)
    {
        return $this->config->getValue(
            self::XML_PATH_TAX_CLASS,
            ScopeInterface::SCOPE_STORE,
            $store
        );
    }

    /**
     * Check ability to display prices including tax for payment fee in shopping cart
     *
     * @param \Magento\Store\Model\Store|int|null $store
     * @return bool
     */
    public function displayCartPaymentFeeIncludeTaxPrice($store = null)
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
     * Check ability to display prices excluding tax for payment fee in shopping cart
     *
     * @param \Magento\Store\Model\Store|int|null $store
     * @return bool
     */
    public function displayCartPaymentFeeExcludeTaxPrice($store = null)
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
     * @param \Magento\Store\Model\Store|int|null $store
     * @return bool
     */
    public function displayCartPaymentFeeBothPrices($store = null)
    {
        $configValue = $this->config->getValue(
            self::XML_PATH_PRICE_DISPLAY_CART_PAYMENT_FEE,
            ScopeInterface::SCOPE_STORE,
            $store
        );
        return $configValue == \Magento\Tax\Model\Config::DISPLAY_TYPE_BOTH;
    }

    /**
     * Check ability to display prices including tax for payment fee in backend sales
     *
     * @param \Magento\Store\Model\Store|int|null $store
     * @return bool
     */
    public function displaySalesPaymentFeeIncludeTaxPrice($store = null)
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
     * Check ability to display prices excluding tax for payment fee in backend sales
     *
     * @param \Magento\Store\Model\Store|int|null $store
     * @return bool
     */
    public function displaySalesPaymentFeeExcludeTaxPrice($store = null)
    {
        $configValue = $this->config->getValue(
            self::XML_PATH_PRICE_DISPLAY_SALES_PAYMENT_FEE,
            ScopeInterface::SCOPE_STORE,
            $store
        );
        return $configValue == \Magento\Tax\Model\Config::DISPLAY_TYPE_EXCLUDING_TAX;
    }

    /**
     * Check ability to display both prices for payment fee in backend sales
     *
     * @param \Magento\Store\Model\Store|int|null $store
     * @return bool
     */
    public function displaySalesPaymentFeeBothPrices($store = null)
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
     * @param   null|string|bool|int|Store $store
     * @return  bool
     */
    public function paymentFeeIncludesTax($store = null)
    {
        $configValue = $this->config->getValue(
            self::CONFIG_XML_PATH_PAYMENT_FEE_INCLUDES_TAX,
            ScopeInterface::SCOPE_STORE,
            $store
        );
        return (bool)$configValue;
    }

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function isUnifaunEnabled($storeId)
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
     * @param int|null $storeId
     * @return string
     */
    public function getUnifaunCheckoutId($storeId = null)
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_UNIFAUN_CHECKOUT_ID, $storeId);
    }

    /**
     * @param int|null $storeId
     * @return array
     */
    public function getUnifaunParameters($storeId = null)
    {
        $str = (string)$this->adapter->getConfigData(self::QLIROONE_UNIFAUN_PARAMETERS, $storeId);
        if ($str) {
            return $this->json->unserialize($str);
        }

        return [];
    }

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function isIngridEnabled($storeId)
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
     * @param int|null $storeId
     * @return bool
     */
    public function getMinimumCustomerAge($storeId = null)
    {
        return (int)$this->adapter->getConfigData(self::QLIROONE_MINIMUM_CUSTOMER_AGE, $storeId);
    }

     /**
     * Check if only B2B checkout is enabled for companies
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isB2BCheckoutOnlyEnabled($storeId = null): bool
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_B2B_CHECKOUT_ONLY, $storeId);
    }

    /**
     * Check if qliro set to be shown as a payment method
     *
     * @param int|null $storeId
     * @return bool
     */
    public function getShowAsPaymentMethod($storeId = null): bool
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_SHOW_AS_PAYMENT_METHOD, $storeId);
    }

    /**
     * How the payment method renders once selected, see Config\Source\PaymentMethodRenderMode
     *
     * @param int|null $storeId
     * @return string
     */
    public function getPaymentMethodRenderMode($storeId = null): string
    {
        return (string)$this->adapter->getConfigData(self::QLIROONE_PAYMENT_METHOD_RENDER_MODE, $storeId);
    }

    /**
     * Whether Qliro renders as an iframe inside the native checkout
     *
     * The mode only exists on top of "show as payment method", so both settings decide it. Every
     * caller asks here rather than pairing the two itself, so they cannot drift apart.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEmbeddedIframeMode($storeId = null): bool
    {
        return $this->getShowAsPaymentMethod($storeId)
            && $this->getPaymentMethodRenderMode($storeId) === PaymentMethodRenderMode::MODE_IFRAME;
    }

    /**
     * Check if the native Magento shipping step must be hidden on the QliroOne checkout page
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isHideNativeShippingStep($storeId = null): bool
    {
        return (bool)$this->adapter->getConfigData(self::QLIROONE_HIDE_NATIVE_SHIPPING_STEP, $storeId);
    }

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function isUseRecurring($storeId = null): bool
    {
        return !!$this->adapter->getConfigData(self::QLIROONE_RECURRING_ENABLE, $storeId);
    }

    /**
     * @param int|null $storeId
     * @return string
     */
    public function getRecurringFrequencyOptions($storeId = null): string
    {
        return $this->adapter->getConfigData(self::QLIROONE_RECURRING_FREQUENCY_OPTIONS, $storeId);
    }

    /**
     * Gets available countries depending on current config:
     * - if "allow specific" is enabled, returns the list of countries from "specific countries" config
     * - otherwise, returns general list of allowed countries
     *
     * @param string $storeId
     * @return array – Option format: ['value' => 'SE', 'label' => 'Sweden']
     */
    public function getAvailableCountries($storeId = null): array
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
     * @param int|null $storeId
     * @return string
     */
    public function getDefaultCountry($storeId = null): string
    {
        return $this->directoryHelper->getDefaultCountry($storeId);
    }
}
