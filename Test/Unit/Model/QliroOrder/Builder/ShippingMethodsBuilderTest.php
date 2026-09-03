<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder;

use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Store\Model\Information;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterface;
use Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterfaceFactory;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodsBuilder;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodsBuilder::create
 *
 * PLIN-389: with Preset Shipping Address on, the quote shipping address is filled from Store
 * Information so a carrier has something to rate before Qliro reports the buyer's address. It
 * belongs here, where the rating happens, and it must not survive the call: the placeholder is
 * the store's own address, and what is left on the quote is what the order is placed with.
 */
class ShippingMethodsBuilderTest extends TestCase
{
    private const COUNTRY = 'NO';

    private Config&MockObject $qliroConfig;
    private Information&MockObject $information;
    private Address&MockObject $shippingAddress;
    private Quote&MockObject $quote;
    private ShippingMethodsBuilder $builder;

    /**
     * @var array The address data as it was when the carriers were asked for a rate
     */
    private array $addressWhenRated = [];

    /**
     * @var bool Whether the rating call throws, standing in for a carrier that fails
     */
    private bool $ratingThrows = false;

    protected function setUp(): void
    {
        $this->qliroConfig = $this->createMock(Config::class);
        $this->information = $this->createMock(Information::class);
        $this->ratingThrows = false;
        $this->shippingAddress = $this->address();

        $this->quote = $this->createMock(Quote::class);
        $this->quote->method('getShippingAddress')->willReturn($this->shippingAddress);
        $this->quote->method('getStore')->willReturn($this->createMock(Store::class));
        $this->quote->method('getIsVirtual')->willReturn(false);

        $responseFactory = $this->createMock(UpdateShippingMethodsResponseInterfaceFactory::class);
        $responseFactory->method('create')
            ->willReturn($this->createMock(UpdateShippingMethodsResponseInterface::class));

        $this->builder = new ShippingMethodsBuilder(
            $responseFactory,
            $this->createMock(ShippingMethodBuilder::class),
            $this->createMock(ManagerInterface::class),
            $this->createMock(StoreManagerInterface::class),
            $this->qliroConfig,
            $this->createMock(LogManager::class),
            $this->information
        );
    }

    /**
     * What the carriers are asked to rate, which is the whole point of the setting.
     */
    public function testRatesOnThePlaceholderAddress(): void
    {
        $this->enablePresetAddress();

        $this->builder->setQuote($this->quote)->create();

        self::assertSame('0155', $this->addressWhenRated['postcode']);
        self::assertSame('Oslo', $this->addressWhenRated['city']);
        self::assertSame(self::COUNTRY, $this->addressWhenRated['country_id']);
    }

    /**
     * The placeholder is the store's own address and the company line of it is the store name.
     * Neither it nor the store phone is written at all: nothing rates on them, and both are read
     * elsewhere as the buyer's own.
     */
    public function testNeverWritesTheStoreNameOrPhone(): void
    {
        $this->enablePresetAddress();

        $this->builder->setQuote($this->quote)->create();

        self::assertArrayNotHasKey('company', $this->addressWhenRated);
        self::assertArrayNotHasKey('telephone', $this->addressWhenRated);
        self::assertNull($this->shippingAddress->getData('company'));
        self::assertNull($this->shippingAddress->getData('telephone'));
    }

    /**
     * Once the rates are collected the placeholder has done its job. It used to be applied when
     * the Qliro order was created and stay on the quote from there on, because the
     * `clearInstance()` meant to drop it clears no data on this model.
     */
    public function testGivesTheQuoteItsOwnAddressBackAfterRating(): void
    {
        $this->enablePresetAddress();

        $this->builder->setQuote($this->quote)->create();

        self::assertNull($this->shippingAddress->getData('street'));
        self::assertNull($this->shippingAddress->getData('city'));
        self::assertNull($this->shippingAddress->getData('postcode'));
        self::assertNull($this->shippingAddress->getData('country_id'));
        self::assertNull($this->shippingAddress->getData('region'));
        self::assertNull($this->shippingAddress->getData('region_id'));
    }

    /**
     * Every rating in the request gets the placeholder, not only the first. The Qliro order is
     * updated from a later one, and `collectShippingRates()` drops the rates it finds first, so a
     * rating on an empty address would leave the order with no shipping method at all.
     */
    public function testAppliesThePlaceholderToEveryRating(): void
    {
        $this->enablePresetAddress();

        $this->builder->setQuote($this->quote)->create();
        $this->addressWhenRated = [];
        $this->builder->setQuote($this->quote)->create();

        self::assertSame('0155', $this->addressWhenRated['postcode']);
        self::assertNull($this->shippingAddress->getData('postcode'));
    }

    /**
     * A carrier that throws must not leave the store's own address on the quote, which is the
     * state the restore exists to prevent.
     */
    public function testGivesTheAddressBackWhenTheRatingThrows(): void
    {
        $this->enablePresetAddress();
        $this->ratingThrows = true;

        try {
            $this->builder->setQuote($this->quote)->create();
            self::fail('The exception was swallowed');
        } catch (\RuntimeException $exception) {
            self::assertNull($this->shippingAddress->getData('postcode'));
            self::assertNull($this->shippingAddress->getData('city'));
        }
    }

    /**
     * An address already on the quote is the buyer's own, and it is rated as it stands.
     */
    public function testLeavesAnAddressThatAlreadyHasAPostcodeAlone(): void
    {
        $this->enablePresetAddress();
        $this->information->expects(self::never())->method('getStoreInformationObject');
        $this->shippingAddress->addData([
            'company' => 'Buyer AS',
            'street' => 'Torsrudveien 10',
            'city' => 'Tranby',
            'postcode' => '3406',
        ]);

        $this->builder->setQuote($this->quote)->create();

        self::assertSame('Buyer AS', $this->shippingAddress->getData('company'));
        self::assertSame('3406', $this->addressWhenRated['postcode']);
        self::assertSame('Tranby', $this->shippingAddress->getData('city'));
    }

    /**
     * With the setting off nothing is preset, and nothing may be restored either.
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
     * A quote address with its real data handling, only the rating stubbed out. The rating call
     * records what the carriers were given, which is what the placeholder exists for.
     *
     * @return \Magento\Quote\Model\Quote\Address&\PHPUnit\Framework\MockObject\MockObject
     */
    private function address(): Address&MockObject
    {
        $address = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['collectShippingRates', 'getGroupedAllShippingRates', 'getAllShippingRates'])
            ->getMock();

        $address->method('getGroupedAllShippingRates')->willReturn([]);
        $address->method('getAllShippingRates')->willReturn([]);
        $address->method('collectShippingRates')->willReturnCallback(
            function () use ($address) {
                $this->addressWhenRated = $address->getData();
                unset($this->addressWhenRated['collect_shipping_rates']);

                if ($this->ratingThrows) {
                    throw new \RuntimeException('carrier down');
                }

                return $address;
            }
        );

        return $address;
    }
}
