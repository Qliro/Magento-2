<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Converter;

use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterface;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface;
use Qliro\QliroOne\Model\QliroOrder\Converter\AddressConverter;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Converter\AddressConverter
 */
class AddressConverterTest extends TestCase
{
    private AddressConverter $converter;

    /**
     * @var array Backing store for the address mock
     */
    private array $addressData = [];

    protected function setUp(): void
    {
        $this->converter = new AddressConverter();
        $this->addressData = [];
    }

    /**
     * The return value is what tells the caller whether updateCustomer applied anything,
     * so a payload that brings new values has to report true and write them through.
     */
    public function testReportsChangeAndWritesTheValues(): void
    {
        $address = $this->address();

        self::assertTrue(
            $this->converter->convert($this->qliroAddress(), $this->qliroCustomer(), $address)
        );
        self::assertSame('11122', $this->addressData['postcode']);
        self::assertSame('Stockholm', $this->addressData['city']);
        self::assertSame(['Sveavagen 1'], $this->addressData['street']);
        self::assertSame('buyer@example.com', $this->addressData['email']);
        self::assertSame('0700000000', $this->addressData['telephone']);
    }

    /**
     * A repeated payload must report false, otherwise every customer info event would count
     * as a change and push a pointless order update to Qliro.
     */
    public function testReportsNoChangeWhenEverythingAlreadyMatches(): void
    {
        $address = $this->address();
        $this->converter->convert($this->qliroAddress(), $this->qliroCustomer(), $address);

        self::assertFalse(
            $this->converter->convert($this->qliroAddress(), $this->qliroCustomer(), $address)
        );
    }

    /**
     * Null fields never overwrite what the quote already holds.
     */
    public function testKeepsExistingValuesWhenThePayloadIsEmpty(): void
    {
        $address = $this->address();
        $this->converter->convert($this->qliroAddress(), $this->qliroCustomer(), $address);

        self::assertFalse($this->converter->convert(null, null, $address));
        self::assertSame('11122', $this->addressData['postcode']);
    }

    /**
     * Without a country Magento cannot collect a single rate, so the country code from the
     * callback fills an empty one.
     */
    public function testSetsCountryWhenTheAddressHasNone(): void
    {
        $address = $this->address();
        $address->method('getCountryId')->willReturn(null);
        $address->expects(self::once())->method('setCountryId')->with('SE');

        self::assertTrue($this->converter->convert(null, null, $address, 'SE'));
    }

    /**
     * The country the quote carries is a guess made when the order was created, so a country
     * Qliro reports later replaces it. Without this a Danish address kept SE and Magento
     * rated the carrier for Sweden, which collects no rate for a Danish postcode.
     */
    public function testOverwritesACountryThatDiffersFromTheOneQliroReports(): void
    {
        $address = $this->address();
        $address->method('getCountryId')->willReturn('SE');
        $address->expects(self::once())->method('setCountryId')->with('DK');

        self::assertTrue(
            $this->converter->convert($this->qliroAddress(), $this->qliroCustomer(), $address, 'DK')
        );
    }

    /**
     * A region was picked in the country it belongs to, so it cannot survive a country change.
     */
    public function testClearsTheRegionWhenTheCountryChanges(): void
    {
        $address = $this->address();
        $address->method('getCountryId')->willReturn('SE');
        $address->expects(self::once())->method('setRegion')->with(null);
        $address->expects(self::once())->method('setRegionId')->with(null);

        $this->converter->convert($this->qliroAddress(), $this->qliroCustomer(), $address, 'DK');
    }

    /**
     * A callback can carry a country and no address at all, and then the country is not enough
     * to replace one: the postcode on the quote would keep pointing at the previous country.
     */
    public function testDoesNotReplaceTheCountryWhenThePayloadCarriesNoAddress(): void
    {
        $address = $this->address();
        $address->method('getCountryId')->willReturn('DK');
        $address->expects(self::never())->method('setCountryId');

        self::assertFalse($this->converter->convert(null, null, $address, 'SE'));
    }

    /**
     * Qliro sends an address object with empty fields while the buyer is still identifying.
     * That is no better than no address, the postcode it would leave behind is the old one.
     */
    public function testDoesNotReplaceTheCountryWhenThePayloadAddressHasNoPostcode(): void
    {
        $qliroAddress = $this->createMock(QliroOrderCustomerAddressInterface::class);
        $qliroAddress->method('getPostalCode')->willReturn(null);

        $address = $this->address();
        $address->method('getCountryId')->willReturn('DK');
        $address->expects(self::never())->method('setCountryId');

        self::assertFalse($this->converter->convert($qliroAddress, null, $address, 'SE'));
    }

    /**
     * A country that already matches is not a change, otherwise every callback would push a
     * pointless order update to Qliro.
     */
    public function testReportsNoChangeWhenTheCountryAlreadyMatches(): void
    {
        $address = $this->address();
        $address->method('getCountryId')->willReturn('FI');
        $address->expects(self::never())->method('setCountryId');

        self::assertFalse($this->converter->convert(null, null, $address, 'FI'));
    }

    /**
     * A payload without a country never clears the one the quote holds.
     */
    public function testKeepsTheCountryWhenThePayloadCarriesNone(): void
    {
        $address = $this->address();
        $address->method('getCountryId')->willReturn('DK');
        $address->expects(self::never())->method('setCountryId');

        self::assertFalse($this->converter->convert(null, null, $address));
    }

    /**
     * An empty country in the payload is not a country. Overwriting on difference without
     * this would replace a working country with an empty string.
     */
    public function testKeepsTheCountryWhenThePayloadCarriesAnEmptyOne(): void
    {
        $address = $this->address();
        $address->method('getCountryId')->willReturn('DK');
        $address->expects(self::never())->method('setCountryId');

        self::assertFalse($this->converter->convert(null, null, $address, ''));
    }

    /**
     * An address copied from the address book must stop pointing at the customer address
     * once Qliro changed any of its values.
     */
    public function testDetachesFromTheCustomerAddressOnChange(): void
    {
        $address = $this->address();
        $address->method('getCustomerAddressId')->willReturn(7);
        $address->expects(self::once())->method('setCustomerAddressId')->with(null);

        $this->converter->convert($this->qliroAddress(), $this->qliroCustomer(), $address);
    }

    private function address(): Address&MockObject
    {
        $address = $this->createMock(Address::class);
        $address->method('getData')->willReturnCallback(
            fn ($key) => $this->addressData[$key] ?? null
        );
        $address->method('setData')->willReturnCallback(
            function ($key, $value) use ($address) {
                $this->addressData[$key] = $value;

                return $address;
            }
        );

        return $address;
    }

    private function qliroAddress(): QliroOrderCustomerAddressInterface&MockObject
    {
        $qliroAddress = $this->createMock(QliroOrderCustomerAddressInterface::class);
        $qliroAddress->method('getFirstName')->willReturn('Ada');
        $qliroAddress->method('getLastName')->willReturn('Lovelace');
        $qliroAddress->method('getStreet')->willReturn(['Sveavagen 1']);
        $qliroAddress->method('getCity')->willReturn('Stockholm');
        $qliroAddress->method('getPostalCode')->willReturn('11122');

        return $qliroAddress;
    }

    private function qliroCustomer(): QliroOrderCustomerInterface&MockObject
    {
        $qliroCustomer = $this->createMock(QliroOrderCustomerInterface::class);
        $qliroCustomer->method('getEmail')->willReturn('buyer@example.com');
        $qliroCustomer->method('getMobileNumber')->willReturn('0700000000');

        return $qliroCustomer;
    }
}
