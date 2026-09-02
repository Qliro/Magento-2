<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder;

use Magento\Customer\Model\Session;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Api\Data\CurrencyInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Store\Model\Information;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderCreateRequestInterface;
use Qliro\QliroOne\Api\Data\QliroOrderCreateRequestInterfaceFactory;
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
 * PLIN-389: with Preset Shipping Address enabled the module fills the quote shipping address from
 * Store Information so a carrier has something to rate before Qliro reports the buyer's address.
 * That placeholder must not survive the call: what it leaves on the quote is what the order is
 * placed with, and a guest order printed the store name on the company line of its shipping address.
 */
class CreateRequestBuilderTest extends TestCase
{
    private const COUNTRY = 'NO';

    private Config&MockObject $qliroConfig;
    private Information&MockObject $information;
    private Address&MockObject $shippingAddress;
    private Quote&MockObject $quote;
    private CreateRequestBuilder $builder;

    /**
     * @var array The address data as it was when the carriers were asked for a rate
     */
    private array $addressWhenRated = [];

    protected function setUp(): void
    {
        $this->qliroConfig = $this->createMock(Config::class);
        $this->information = $this->createMock(Information::class);
        $this->shippingAddress = $this->address();
        $this->quote = $this->createMock(Quote::class);

        $this->quote->method('getShippingAddress')->willReturn($this->shippingAddress);
        $this->quote->method('getBillingAddress')->willReturn($this->address());
        $this->quote->method('getStore')->willReturn($this->createMock(Store::class));
        $this->quote->method('getCurrency')->willReturn($this->createMock(CurrencyInterface::class));

        $createRequest = $this->createMock(QliroOrderCreateRequestInterface::class);
        $createRequest->method('getCountry')->willReturn(self::COUNTRY);
        $createRequestFactory = $this->createMock(QliroOrderCreateRequestInterfaceFactory::class);
        $createRequestFactory->method('create')->willReturn($createRequest);

        $shippingMethods = $this->createMock(UpdateShippingMethodsResponseInterface::class);
        $shippingMethods->method('getAvailableShippingMethods')->willReturn([]);
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
            $this->information,
            $this->createMock(ManagerInterface::class),
            $this->createMock(CountrySelect::class),
            $this->createMock(LogManager::class)
        );
    }

    /**
     * The placeholder is the store's own address, and the company line of it is the store name.
     * It is never written to the quote: nothing rates on a company, and it reached the order.
     */
    public function testNeverWritesTheStoreNameOrPhoneToTheQuote(): void
    {
        $this->enablePresetAddress();

        $this->builder->setQuote($this->quote)->create();

        self::assertArrayNotHasKey('company', $this->addressWhenRated);
        self::assertArrayNotHasKey('telephone', $this->addressWhenRated);
        self::assertNull($this->shippingAddress->getData('company'));
        self::assertNull($this->shippingAddress->getData('telephone'));
    }

    /**
     * The carriers still need something to rate, which is the whole point of the preset address.
     */
    public function testRatesShippingOnThePlaceholderAddress(): void
    {
        $this->enablePresetAddress();

        $this->builder->setQuote($this->quote)->create();

        self::assertSame('0155', $this->addressWhenRated['postcode']);
        self::assertSame('Oslo', $this->addressWhenRated['city']);
        self::assertSame(self::COUNTRY, $this->addressWhenRated['country_id']);
    }

    /**
     * Once the rates are collected the placeholder has done its job. It used to stay on the quote,
     * because the clearInstance() that was meant to drop it clears no data on this model.
     */
    public function testGivesTheQuoteItsOwnAddressBackAfterRating(): void
    {
        $this->enablePresetAddress();

        $this->builder->setQuote($this->quote)->create();

        self::assertNull($this->shippingAddress->getData('street'));
        self::assertNull($this->shippingAddress->getData('city'));
        self::assertNull($this->shippingAddress->getData('postcode'));
        self::assertNull($this->shippingAddress->getData('region'));
        self::assertNull($this->shippingAddress->getData('region_id'));
    }

    /**
     * An address already on the quote is the customer's own, not a placeholder to be cleaned up.
     * The postcode is what decides that, not the customer group: it is a logged-in customer with
     * a default shipping address in the reported case, and a guest who got that far in another.
     */
    public function testLeavesAnAddressThatAlreadyHasAPostcodeAlone(): void
    {
        $this->enablePresetAddress();
        $this->information->expects(self::never())->method('getStoreInformationObject');
        $this->shippingAddress->addData([
            'company' => 'Buyer AS',
            'street' => "Torsrudveien 10",
            'city' => 'Tranby',
            'postcode' => '3406',
        ]);

        $this->builder->setQuote($this->quote)->create();

        self::assertSame('Buyer AS', $this->shippingAddress->getData('company'));
        self::assertSame('3406', $this->shippingAddress->getData('postcode'));
        self::assertSame('Tranby', $this->shippingAddress->getData('city'));
    }

    /**
     * With the setting off nothing is preset, and nothing may be cleared either.
     */
    public function testDoesNotTouchTheAddressWhenThePresetIsDisabled(): void
    {
        $this->qliroConfig->method('presetAddress')->willReturn(false);
        $this->information->expects(self::never())->method('getStoreInformationObject');

        $this->builder->setQuote($this->quote)->create();

        self::assertSame([], $this->addressWhenRated);
    }

    private function enablePresetAddress(): void
    {
        $this->qliroConfig->method('presetAddress')->willReturn(true);
        $this->information->method('getStoreInformationObject')->willReturn(new DataObject([
            'name' => 'Extra Leker',
            'phone' => '+4700000000',
            'street_line1' => 'Storgata 1',
            'street_line2' => '',
            'city' => 'Oslo',
            'postcode' => '01 55',
            'region_id' => null,
            'region' => null,
            'country_id' => self::COUNTRY,
        ]));
    }

    /**
     * A quote address with its real data handling, only the persistence stubbed out. The rating
     * call records what the carriers were given, which is what the preset address is there for.
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
        $address->method('collectShippingRates')->willReturnCallback(
            function () use ($address) {
                $this->addressWhenRated = $address->getData();
                unset($this->addressWhenRated['collect_shipping_rates']);

                return $address;
            }
        );

        return $address;
    }
}
