<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder;

use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote as QuoteModel;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Quote\Model\ResourceModel\Quote\Address as AddressResource;
use Magento\Shipping\Model\Config as ShippingConfig;
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
 * @see \Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodsBuilder
 *
 * PLIN-376: the store's own address is put on the quote so that a carrier which answers only a
 * complete destination returns something before the buyer has identified. Putting it back on the
 * object afterwards was not enough. A carrier rating it can save the address it was handed, the
 * update path saves no quote afterwards, and the placeholder stayed in the database: the buyer's
 * street, city and postcode were written over it later while the region never was, and Vajper's
 * order 000008764 was placed as Stockholm 11329 in Västmanlands län with a delivery to a service
 * point in Västerås.
 */
class ShippingMethodsBuilderPresetAddressTest extends TestCase
{
    private const STORE_ID = 1;

    /**
     * What the placeholder writes, and so what a carrier saving the address it was handed leaves
     * in the row
     */
    private const PLACEHOLDER_ROW = [
        'street' => "Saltängsvägen 33\n",
        'city' => 'Västerås',
        'postcode' => '72132',
        'country_id' => 'SE',
    ];

    private Config&MockObject $qliroConfig;

    private Information&MockObject $information;

    protected function setUp(): void
    {
        $this->qliroConfig = $this->createMock(Config::class);
        $this->qliroConfig->method('isUnifaunEnabled')->willReturn(false);
        $this->qliroConfig->method('isIngridEnabled')->willReturn(false);

        $this->information = $this->createMock(Information::class);
        $this->information->method('getStoreInformationObject')->willReturn(new DataObject([
            'street_line1' => 'Saltängsvägen 33',
            'street_line2' => '',
            'city' => 'Västerås',
            'postcode' => '721 32',
            'region' => 'Västmanlands län',
            'region_id' => 1072,
            'country_id' => 'SE',
        ]));
    }

    /**
     * The rates belong to the placeholder as much as the address does: an option rated for the
     * store is not one the buyer can be given, and leaving them behind let a delivery to the
     * store's own town be chosen for an address hundreds of kilometres away.
     */
    public function testTakesThePlaceholderBackOutOfTheDatabase(): void
    {
        $this->qliroConfig->method('presetAddress')->willReturn(true);

        // The row holds the placeholder, which is what a carrier saving the address it was
        // handed leaves behind
        $address = $this->address(559004, [], self::PLACEHOLDER_ROW);
        $address->expects(self::once())->method('removeAllShippingRates');

        $written = null;
        $address->expects(self::once())->method('save')->willReturnCallback(
            function () use ($address, &$written) {
                $written = $address->getData();

                return $address;
            }
        );

        $this->builder($address)->setQuote($this->quote($address))->create();

        self::assertNull($address->getData('postcode'), 'the buyer keeps an empty address, not the store one');
        self::assertNull($address->getData('region_id'), 'and the store region does not outlive the rating');

        /*
         * Present and null, not absent. Magento builds the update from the keys the object still
         * carries, so a key merely dropped leaves the placeholder's value standing in the column,
         * which is how the store region survived under the buyer's own street.
         */
        foreach (['street', 'city', 'postcode', 'region', 'region_id', 'country_id'] as $key) {
            self::assertArrayHasKey($key, $written, sprintf('%s has to be written, not omitted', $key));
            self::assertNull($written[$key], sprintf('%s has to be cleared', $key));
        }
    }

    /**
     * A quote the buyer has not reached yet has no address row to correct, and saving one would
     * write a placeholder nobody asked for.
     */
    public function testLeavesAnUnsavedAddressAlone(): void
    {
        $this->qliroConfig->method('presetAddress')->willReturn(true);

        $address = $this->address(null);
        $address->expects(self::never())->method('save');

        $this->builder($address)->setQuote($this->quote($address))->create();
    }

    /**
     * With the setting off nothing is put on the quote, so there is nothing to take back off it
     * and no write to make.
     */
    public function testWritesNothingWhenNoPlaceholderWasApplied(): void
    {
        $this->qliroConfig->method('presetAddress')->willReturn(false);

        $address = $this->address(559004);
        $address->expects(self::never())->method('removeAllShippingRates');
        $address->expects(self::never())->method('save');

        $this->builder($address)->setQuote($this->quote($address))->create();
    }

    /**
     * An address the carriers can already rate is the buyer's own, and the placeholder stays out
     * of it.
     */
    public function testWritesNothingWhenTheBuyerAlreadyHasAPostcode(): void
    {
        $this->qliroConfig->method('presetAddress')->willReturn(true);

        $address = $this->address(559004, ['postcode' => '11329']);
        $address->expects(self::never())->method('save');

        $this->builder($address)->setQuote($this->quote($address))->create();

        self::assertSame('11329', $address->getData('postcode'));
    }

    /**
     * The rating takes seconds and Qliro's callbacks write to the same row without a session to
     * serialise them against it. A row that already holds the buyer's own address is left alone,
     * because putting the snapshot back would empty an address somebody had just filled in.
     */
    public function testLeavesARowAQliroCallbackHasAlreadyFilledIn(): void
    {
        $this->qliroConfig->method('presetAddress')->willReturn(true);

        $address = $this->address(559004, [], [
            'street' => 'Observatoriegatan 21',
            'city' => 'Stockholm',
            'postcode' => '11329',
            'country_id' => 'SE',
        ]);
        $address->expects(self::never())->method('save');

        $this->builder($address)->setQuote($this->quote($address))->create();
    }

    /**
     * A quote address whose data behaves like the real one, so the placeholder can be seen going
     * on and coming back off
     *
     * @param int|null $addressId
     * @param array $data
     * @return QuoteAddress&MockObject
     */
    private function address(?int $addressId, array $data = [], ?array $storedRow = null): QuoteAddress&MockObject
    {
        $store = $data;

        $address = $this->getMockBuilder(QuoteAddress::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'getId',
                'getResource',
                'getData',
                'addData',
                'setData',
                'save',
                'collectShippingRates',
                'removeAllShippingRates',
                'getGroupedAllShippingRates',
                'getAllShippingRates',
                'getPostcode',
            ])
            ->addMethods(['setCollectShippingRates'])
            ->getMock();

        $address->method('getId')->willReturn($addressId);
        $address->method('getResource')->willReturn($this->addressResource($storedRow));
        // A closure and not an arrow function: an arrow function captures by value, so it would
        // keep answering with the array as it was when the mock was built
        $address->method('getData')->willReturnCallback(
            function ($key = '') use (&$store) {
                return ($key === '' || $key === null) ? $store : ($store[$key] ?? null);
            }
        );
        $address->method('addData')->willReturnCallback(
            function (array $values) use (&$store, $address) {
                $store = array_merge($store, $values);

                return $address;
            }
        );
        // Replaces rather than merges, as the real one does when it is handed an array
        $address->method('setData')->willReturnCallback(
            function ($key, $value = null) use (&$store, $address) {
                if (is_array($key)) {
                    $store = $key;
                } else {
                    $store[$key] = $value;
                }

                return $address;
            }
        );
        $address->method('getPostcode')->willReturnCallback(function () use (&$store) { return $store['postcode'] ?? null; });
        $address->method('setCollectShippingRates')->willReturnSelf();
        $address->method('collectShippingRates')->willReturnSelf();
        $address->method('getGroupedAllShippingRates')->willReturn([]);
        $address->method('getAllShippingRates')->willReturn([]);

        return $address;
    }

    /**
     * The resource behind the address, answering with whatever the row currently holds
     *
     * The restore reads the stored postcode before it writes, so that a row a Qliro callback has
     * already filled with the buyer's own address is left alone.
     *
     * @param array|null $storedRow What the row holds, which is not what the object holds
     * @return AddressResource&MockObject
     */
    private function addressResource(?array $storedRow): AddressResource&MockObject
    {
        // The fluent select has to answer with itself, or the read below is an error the caller
        // treats as "cannot tell", and nothing is written
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();

        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchRow')->willReturn($storedRow);

        $resource = $this->createMock(AddressResource::class);
        $resource->method('getConnection')->willReturn($connection);
        $resource->method('getMainTable')->willReturn('quote_address');

        return $resource;
    }

    /**
     * @param QuoteAddress&MockObject $address
     * @return QuoteModel&MockObject
     */
    private function quote(QuoteAddress $address): QuoteModel&MockObject
    {
        $quote = $this->getMockBuilder(QuoteModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getStoreId', 'getIsVirtual', 'getShippingAddress', 'collectTotals', 'getStore'])
            ->addMethods(['setTotalsCollectedFlag'])
            ->getMock();

        $quote->method('getId')->willReturn(284324);
        $quote->method('getStoreId')->willReturn(self::STORE_ID);
        $quote->method('getIsVirtual')->willReturn(false);
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getStore')->willReturn($this->createMock(Store::class));

        return $quote;
    }

    /**
     * @param QuoteAddress&MockObject $address
     * @return ShippingMethodsBuilder
     */
    private function builder(QuoteAddress $address): ShippingMethodsBuilder
    {
        $responseFactory = $this->createMock(UpdateShippingMethodsResponseInterfaceFactory::class);
        $responseFactory->method('create')
            ->willReturn($this->createMock(UpdateShippingMethodsResponseInterface::class));

        return new ShippingMethodsBuilder(
            $responseFactory,
            $this->createMock(ShippingMethodBuilder::class),
            $this->createMock(ManagerInterface::class),
            $this->createMock(StoreManagerInterface::class),
            $this->qliroConfig,
            $this->createMock(LogManager::class),
            $this->information,
            $this->createMock(ShippingConfig::class)
        );
    }
}
