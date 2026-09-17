<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder;

use Magento\Customer\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Api\Data\CurrencyInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderCreateRequestInterface;
use Qliro\QliroOne\Api\Data\QliroOrderCreateRequestInterfaceFactory;
use Qliro\QliroOne\Api\Data\QliroOrderShippingMethodInterface;
use Qliro\QliroOne\Api\Data\QliroOrderShippingMethodInterfaceFactory;
use Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterface;
use Qliro\QliroOne\Api\GeoIpResolverInterface;
use Qliro\QliroOne\Api\LanguageMapperInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Management\CountrySelect;
use Qliro\QliroOne\Model\QliroOrder\Builder\CreateRequestBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\CustomerBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\OrderItemsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\ShippingConfigBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodsBuilder;
use Qliro\QliroOne\Model\QliroOrder\Customer;
use Qliro\QliroOne\Service\Callback\UrlBuilder as CallbackUrlBuilder;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Builder\CreateRequestBuilder::create
 *
 * PLIN-419: two things the iframe mode changes about the create request. Qliro is told to lock the
 * customer block, at the top level of the request where it reads the flag. And the country the
 * buyer entered in the native checkout stays on the quote, because getCountry() answers from the
 * country selector, GeoIP and the store default and has never looked at the quote.
 */
class CreateRequestBuilderIframeTest extends TestCase
{
    private const REQUEST_COUNTRY = 'NO';
    private const BUYER_COUNTRY = 'DK';

    private Config&MockObject $qliroConfig;
    private Address&MockObject $shippingAddress;
    private Address&MockObject $billingAddress;
    private Quote&MockObject $quote;
    private ScopeConfigInterface&MockObject $scopeConfig;
    private QliroOrderCreateRequestInterface&MockObject $createRequest;
    private CreateRequestBuilder $builder;

    protected function setUp(): void
    {
        $this->qliroConfig = $this->createMock(Config::class);

        $this->shippingAddress = $this->address();
        $this->shippingAddress->setData('country_id', self::BUYER_COUNTRY);
        $this->billingAddress = $this->address();
        $this->billingAddress->setData('country_id', self::BUYER_COUNTRY);

        $this->quote = $this->createMock(Quote::class);
        $this->quote->method('getShippingAddress')->willReturn($this->shippingAddress);
        $this->quote->method('getBillingAddress')->willReturn($this->billingAddress);
        $this->quote->method('getStore')->willReturn($this->createMock(Store::class));
        $this->quote->method('getCurrency')->willReturn($this->createMock(CurrencyInterface::class));
        $this->quote->method('getStoreId')->willReturn(1);

        $this->createRequest = $this->createMock(QliroOrderCreateRequestInterface::class);
        $this->createRequest->method('getCountry')->willReturn(self::REQUEST_COUNTRY);
        $createRequestFactory = $this->createMock(QliroOrderCreateRequestInterfaceFactory::class);
        $createRequestFactory->method('create')->willReturn($this->createRequest);

        $shippingMethods = $this->createMock(UpdateShippingMethodsResponseInterface::class);
        $shippingMethods->method('getAvailableShippingMethods')->willReturn([
            $this->createMock(QliroOrderShippingMethodInterface::class),
        ]);
        $shippingMethodsBuilder = $this->createMock(ShippingMethodsBuilder::class);
        $shippingMethodsBuilder->method('setQuote')->willReturnSelf();
        $shippingMethodsBuilder->method('create')->willReturn($shippingMethods);

        $orderItemsBuilder = $this->createMock(OrderItemsBuilder::class);
        $orderItemsBuilder->method('setQuote')->willReturnSelf();
        $orderItemsBuilder->method('create')->willReturn([]);

        $shippingConfigBuilder = $this->createMock(ShippingConfigBuilder::class);
        $shippingConfigBuilder->method('setQuote')->willReturnSelf();

        // An identified buyer, so the request reaches the branch that carries the customer block
        $customer = new Customer();
        $customer->setEmail('alex@example.com');

        $customerBuilder = $this->createMock(CustomerBuilder::class);
        $customerBuilder->method('setQuote')->willReturnSelf();
        $customerBuilder->method('setCustomer')->willReturnSelf();
        $customerBuilder->method('create')->willReturn($customer);

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($this->createMock(Store::class));

        // Stands in for general/country/default, the last answer getCountry() falls back to
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->scopeConfig->method('getValue')->willReturn(self::REQUEST_COUNTRY);

        $this->builder = new CreateRequestBuilder(
            $createRequestFactory,
            $customerBuilder,
            $orderItemsBuilder,
            $this->createMock(QliroOrderShippingMethodInterfaceFactory::class),
            $this->createMock(LanguageMapperInterface::class),
            $this->qliroConfig,
            $this->scopeConfig,
            $this->createMock(Session::class),
            $storeManager,
            $this->createMock(GeoIpResolverInterface::class),
            $this->createMock(CallbackUrlBuilder::class),
            $shippingMethodsBuilder,
            $shippingConfigBuilder,
            $this->createMock(ManagerInterface::class),
            $this->createMock(CountrySelect::class),
            $this->createMock(LogManager::class)
        );
    }

    /**
     * Qliro reads the flag at the top level of the create request. Nested in CustomerInformation,
     * where the per field Lock* flags live, it is read by nothing.
     */
    public function testTheIframeModeLocksTheCustomerBlock(): void
    {
        $this->qliroConfig->method('isEmbeddedIframeMode')->willReturn(true);

        $this->createRequest->expects(self::once())->method('setLockCustomerInformation')->with(true);

        $this->builder->setQuote($this->quote)->create();
    }

    /**
     * Nothing about the payload changes for a store that has not turned the mode on.
     */
    public function testTheRedirectModeSendsNoLockFlag(): void
    {
        $this->qliroConfig->method('isEmbeddedIframeMode')->willReturn(false);

        $this->createRequest->expects(self::never())->method('setLockCustomerInformation');

        $this->builder->setQuote($this->quote)->create();
    }

    /**
     * The buyer entered a country in the native checkout and the rates were collected for it.
     * Replacing it here would move the order to another country and leave behind a delivery method
     * and a tax that belong to the country the buyer never chose.
     */
    public function testTheIframeModeKeepsTheCountryTheBuyerEntered(): void
    {
        $this->qliroConfig->method('isEmbeddedIframeMode')->willReturn(true);

        $this->builder->setQuote($this->quote)->create();

        self::assertSame(self::BUYER_COUNTRY, $this->shippingAddress->getData('country_id'));
        self::assertSame(self::BUYER_COUNTRY, $this->billingAddress->getData('country_id'));
    }

    /**
     * The redirect mode keeps the behaviour it has always had: Qliro owns the address there, and
     * the quote is moved to the country the Qliro order was created for.
     */
    public function testTheRedirectModeStillWritesTheRequestCountry(): void
    {
        $this->qliroConfig->method('isEmbeddedIframeMode')->willReturn(false);

        $this->builder->setQuote($this->quote)->create();

        self::assertSame(self::REQUEST_COUNTRY, $this->shippingAddress->getData('country_id'));
        self::assertSame(self::REQUEST_COUNTRY, $this->billingAddress->getData('country_id'));
    }

    /**
     * Skipping the write-back is only half of it. The order is created for a country too, and that
     * country comes from the selector, GeoIP and the store default. Leaving it alone would hand
     * Qliro a locked DK address under SE rules, with no field for the buyer to correct.
     */
    public function testTheIframeModeCreatesTheOrderForTheCountryTheBuyerEntered(): void
    {
        $this->qliroConfig->method('isEmbeddedIframeMode')->willReturn(true);

        $this->createRequest->expects(self::once())->method('setCountry')->with(self::BUYER_COUNTRY);

        $this->builder->setQuote($this->quote)->create();
    }

    /**
     * With no country on the quote yet, the old resolution still answers.
     */
    public function testAnEmptyQuoteCountryFallsBackToTheResolvedOne(): void
    {
        $this->qliroConfig->method('isEmbeddedIframeMode')->willReturn(true);
        $this->shippingAddress->setData('country_id', null);

        $this->createRequest->expects(self::once())->method('setCountry')->with(self::REQUEST_COUNTRY);

        $this->builder->setQuote($this->quote)->create();
    }

    /**
     * A quote address with its real data handling, only the persistence stubbed out.
     *
     * @return Address&MockObject
     */
    private function address(): Address&MockObject
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['collectShippingRates', 'save'])
            ->getMock();

        $address->method('save')->willReturnSelf();
        $address->method('collectShippingRates')->willReturnSelf();

        return $address;
    }
}
