<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\MerchantPayment\Builder;

use Magento\Checkout\Block\Cart\Shipping;
use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Quote\Model\Quote;
use \Magento\Sales\Model\Order;
use Magento\Framework\Url\QueryParamsResolverInterface;
use Qliro\QliroOne\Api\LanguageMapperInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Security\CallbackToken;
use Qliro\QliroOne\Api\Data\AdminCreateMerchantPaymentRequestInterfaceFactory;
use Qliro\QliroOne\Api\Data\AdminCreateMerchantPaymentRequestInterface;
use Qliro\QliroOne\Model\MerchantPayment\Builder\CustomerBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\CustomerAddressBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\OrderItemsBuilder;
use Qliro\QliroOne\Model\MerchantPayment\Builder\PaymentMethodBuilder;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\InvoiceFeeHandler;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\ShippingFeeHandler;

/**
 * QliroOne Merchant Payment create request builder class
 */
class CreateRequestBuilder
{
    /**
     * @var string|null
     */
    private ?string $generatedToken = null;

    /**
     * @var \Magento\Quote\Model\Quote|null
     */
    private ?Quote $quote = null;

    /**
     * @var \Magento\Sales\Model\Order
     */
    private ?Order $order = null;

    /**
     * @var \Qliro\QliroOne\Api\Data\AdminCreateMerchantPaymentRequestInterfaceFactory
     */
    private AdminCreateMerchantPaymentRequestInterfaceFactory $createRequestFactory;

    /**
     * @var \Qliro\QliroOne\Api\LanguageMapperInterface
     */
    private LanguageMapperInterface $languageMapper;

    /**
     * @var \Qliro\QliroOne\Model\Config
     */
    private Config $qliroConfig;

    /**
     * @var \Qliro\QliroOne\Model\MerchantPayment\Builder\CustomerBuilder
     */
    private CustomerBuilder $customerBuilder;

    /**
     * @var \Qliro\QliroOne\Model\QliroOrder\Builder\CustomerAddressBuilder
     */
    private CustomerAddressBuilder $customerAddressBuilder;

    /**
     * @var \Qliro\QliroOne\Model\QliroOrder\Builder\OrderItemsBuilder
     */
    private OrderItemsBuilder $orderItemsBuilder;

    /**
     * @var \Qliro\QliroOne\Model\MerchantPayment\Builder\PaymentMethodBuilder
     */
    private PaymentMethodBuilder $paymentMethodBuilder;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var \Qliro\QliroOne\Model\Security\CallbackToken
     */
    private CallbackToken $callbackToken;

    /**
     * @var \Magento\Framework\Url\QueryParamsResolverInterface
     */
    private QueryParamsResolverInterface $queryParamsResolver;

    /**
     * @var \Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\ShippingFeeHandler
     */
    private $shippingFeeHandler;

    /**
     * @var \Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\InvoiceFeeHandler
     */
    private $invoiceFeeHandler;

    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    private ManagerInterface $eventManager;

    public function __construct(
        AdminCreateMerchantPaymentRequestInterfaceFactory $createRequestFactory,
        CustomerBuilder $customerBuilder,
        CustomerAddressBuilder $customerAddressBuilder,
        OrderItemsBuilder $orderItemsBuilder,
        PaymentMethodBuilder $paymentMethodBuilder,
        LanguageMapperInterface $languageMapper,
        Config $qliroConfig,
        StoreManagerInterface $storeManager,
        CallbackToken $callbackToken,
        QueryParamsResolverInterface $queryParamsResolver,
        ShippingFeeHandler $shippingFeeHandler,
        InvoiceFeeHandler $invoiceFeeHandler,
        ManagerInterface $eventManager
    ) {
        $this->createRequestFactory = $createRequestFactory;
        $this->customerBuilder = $customerBuilder;
        $this->customerAddressBuilder = $customerAddressBuilder;
        $this->orderItemsBuilder = $orderItemsBuilder;
        $this->paymentMethodBuilder = $paymentMethodBuilder;
        $this->languageMapper = $languageMapper;
        $this->qliroConfig = $qliroConfig;
        $this->storeManager = $storeManager;
        $this->callbackToken = $callbackToken;
        $this->queryParamsResolver = $queryParamsResolver;
        $this->shippingFeeHandler = $shippingFeeHandler;
        $this->invoiceFeeHandler = $invoiceFeeHandler;
        $this->eventManager = $eventManager;
    }

    /**
     * Set quote for data extraction
     *
     * @param \Magento\Quote\Api\Data\CartInterface $quote
     * @return $this
     */
    public function setQuote(CartInterface $quote)
    {
        $this->quote = $quote;

        return $this;
    }

    /**
     * Set order for data extraction
     *
     * @param \Magento\Sales\Model\Order $order
     * @return $this
     */
    public function setOrder(Order $order)
    {
        $this->order = $order;

        return $this;
    }

    /**
     * Generate a QliroOne order create request object
     *
     * @return \Qliro\QliroOne\Api\Data\AdminCreateMerchantPaymentRequestInterface
     * @throws \Exception
     */
    public function create()
    {
        if (empty($this->quote)) {
            throw new \LogicException('Quote entity is not set.');
        }

        $createRequest = $this->prepareCreateRequest();
        $createRequest->setMerchantApiKey($this->qliroConfig->getMerchantApiKey($this->quote->getStoreId()));

        $orderItems = $this->orderItemsBuilder->setQuote($this->quote)->create();
        $orderItems = $this->shippingFeeHandler->handle($orderItems, $this->order);

        $createRequest->setOrderItems($orderItems);

        $this->customerBuilder->setQuote($this->quote);
        $customer = $this->customerBuilder->create();
        $createRequest->setCustomer($customer);

        $this->customerAddressBuilder->setAddress($this->quote->getBillingAddress());
        $createRequest->setBillingAddress($this->customerAddressBuilder->create());

        if (!$this->quote->isVirtual()) {
            $this->customerAddressBuilder->setAddress($this->quote->getShippingAddress());
            $createRequest->setShippingAddress($this->customerAddressBuilder->create());
        }

        $paymentMethod = $this->paymentMethodBuilder->setQuote($this->quote)->create();
        $createRequest->setPaymentMethod($paymentMethod);

        $this->eventManager->dispatch(
            'qliroone_order_create_request_build_after',
            [
                'quote' => $this->quote,
                'container' => $createRequest,
            ]
        );

        $this->quote = null;

        return $createRequest;
    }

    /**
     * @return \Qliro\QliroOne\Api\Data\AdminCreateMerchantPaymentRequestInterface
     */
    private function prepareCreateRequest(): AdminCreateMerchantPaymentRequestInterface
    {
        /** @var \Magento\Quote\Api\Data\CurrencyInterface $currencies */
        $currencies = $this->quote->getCurrency();

        $createRequest = $this->createRequestFactory->create();

        $createRequest->setCurrency($currencies->getQuoteCurrencyCode());
        $createRequest->setLanguage($this->languageMapper->getLanguage($this->quote->getStoreId()));
        $createRequest->setCountry($this->getCountry());

        $createRequest->setMerchantOrderManagementStatusPushUrl(
            $this->getCallbackUrl('checkout/qliro_callback/transactionStatus')
        );

        return $createRequest;
    }

    /**
     * @return string
     */
    private function getCountry(): string
    {
        if ($this->quote->getIsVirtual()) {
            return $this->quote->getBillingAddress()->getCountryId();
        }
        return $this->quote->getShippingAddress()->getCountryId();
    }

    /**
     * Get a callback URL with provided path and generated token
     *
     * @param string $path
     * @return string
     */
    private function getCallbackUrl($path)
    {
        $params['_query']['token'] = $this->generateCallbackToken();

        if ($this->qliroConfig->isDebugMode()) {
            $params['_query']['XDEBUG_SESSION_START'] = $this->qliroConfig->getCallbackXdebugSessionFlagName();
        }

        if ($this->qliroConfig->redirectCallbacks() && ($baseUri = $this->qliroConfig->getCallbackUri())) {
            $url = implode('/', [rtrim($baseUri, '/'), ltrim($path, '/')]);

            $this->queryParamsResolver->addQueryParams($params['_query']);
            $queryString = $this->queryParamsResolver->getQuery();
            $url .= '?' . $queryString;

            return $this->applyHttpAuth($url);
        }

        return $this->applyHttpAuth($this->getUrl($path, $params));
    }

    /**
     * Apply HTTP authentication credentials if specified
     *
     * @param string $url
     * @return string
     */
    private function applyHttpAuth($url)
    {
        if ($this->qliroConfig->isHttpAuthEnabled() && preg_match('#^(https?://)(.+)$#', $url, $match)) {
            $authUsername = $this->qliroConfig->getCallbackHttpAuthUsername();
            $authPassword = $this->qliroConfig->getCallbackHttpAuthPassword();

            $url = sprintf('%s%s:%s@%s', $match[1], \urlencode($authUsername), \urlencode($authPassword), $match[2]);
        }

        return $url;
    }

    /**
     * Get a store-specific URL with provided path and optional parameters
     *
     * @param string $path
     * @param array $params
     * @return string
     */
    private function getUrl($path, $params = [])
    {
        /** @var \Magento\Store\Model\Store $store */
        $store = $this->storeManager->getStore();

        return $store->getUrl($path, $params);
    }

    /**
     * @return string
     */
    private function generateCallbackToken()
    {
        if (!$this->generatedToken) {
            $this->generatedToken = $this->callbackToken->getToken();
        }

        return $this->generatedToken;
    }
}
