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
 * PLIN-389: the preset shipping address used to be applied here and was left on the quote, so a
 * guest order printed the store name on the company line of its shipping address. It belongs to
 * `ShippingMethodsBuilder` now, at the one place that rates, and this class writes nothing to the
 * address at all.
 */
class CreateRequestBuilderTest extends TestCase
{
    private const COUNTRY = 'NO';

    private Config&MockObject $qliroConfig;
    private Address&MockObject $shippingAddress;
    private Quote&MockObject $quote;
    private QliroOrderCreateRequestInterface&MockObject $createRequest;
    private CreateRequestBuilder $builder;

    protected function setUp(): void
    {
        $this->qliroConfig = $this->createMock(Config::class);
        $this->shippingAddress = $this->address();
        $this->quote = $this->createMock(Quote::class);

        $this->quote->method('getShippingAddress')->willReturn($this->shippingAddress);
        $this->quote->method('getBillingAddress')->willReturn($this->address());
        $this->quote->method('getStore')->willReturn($this->createMock(Store::class));
        $this->quote->method('getCurrency')->willReturn($this->createMock(CurrencyInterface::class));

        $this->createRequest = $this->createMock(QliroOrderCreateRequestInterface::class);
        $this->createRequest->method('getCountry')->willReturn(self::COUNTRY);
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

        $customerBuilder = $this->createMock(CustomerBuilder::class);
        $customerBuilder->method('setQuote')->willReturnSelf();
        $customerBuilder->method('setCustomer')->willReturnSelf();
        $customerBuilder->method('create')->willReturn(new Customer());

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($this->createMock(Store::class));

        $this->builder = new CreateRequestBuilder(
            $createRequestFactory,
            $customerBuilder,
            $orderItemsBuilder,
            $this->createMock(QliroOrderShippingMethodInterfaceFactory::class),
            $this->createMock(LanguageMapperInterface::class),
            $this->qliroConfig,
            $this->createMock(ScopeConfigInterface::class),
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
     * The shipping address is left as the quote holds it, with the preset setting on as well.
     * Everything the placeholder used to write here reached the order, the store name included.
     */
    public function testWritesNothingToTheShippingAddress(): void
    {
        $this->qliroConfig->method('presetAddress')->willReturn(true);

        $this->builder->setQuote($this->quote)->create();

        self::assertNull($this->shippingAddress->getData('company'));
        self::assertNull($this->shippingAddress->getData('telephone'));
        self::assertNull($this->shippingAddress->getData('street'));
        self::assertNull($this->shippingAddress->getData('city'));
        self::assertNull($this->shippingAddress->getData('postcode'));
        self::assertNull($this->shippingAddress->getData('region'));
        self::assertNull($this->shippingAddress->getData('region_id'));
    }

    /**
     * The country is the one thing the request does put on the quote, and it is the country the
     * Qliro order was created for.
     */
    public function testSetsTheRequestCountryOnTheQuote(): void
    {
        $this->builder->setQuote($this->quote)->create();

        self::assertSame(self::COUNTRY, $this->shippingAddress->getData('country_id'));
    }

    /**
     * The shipping methods the builder collected are what the Qliro order is created with.
     */
    public function testSendsTheCollectedShippingMethods(): void
    {
        $this->createRequest->expects(self::once())
            ->method('setAvailableShippingMethods')
            ->with(self::countOf(1));

        $this->builder->setQuote($this->quote)->create();
    }

    /**
     * A quote address with its real data handling, only the persistence stubbed out.
     *
     * @return \Magento\Quote\Model\Quote\Address&\PHPUnit\Framework\MockObject\MockObject
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
