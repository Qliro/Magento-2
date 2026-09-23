<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Converter;

use Magento\Directory\Helper\Data as DirectoryHelper;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterface;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface;
use Qliro\QliroOne\Model\QliroOrder\Converter\AddressConverter;
use Qliro\QliroOne\Model\QliroOrder\Customer;

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
        $directoryHelper = $this->createMock(DirectoryHelper::class);
        $directoryHelper->method('isRegionRequired')->willReturn(false);

        $this->converter = new AddressConverter($directoryHelper);
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

    /**
     * The shipping placeholder put the store's own region on the quote, and the loop above could
     * never take it off again: Qliro sends no region, so nothing overwrote it, and only a change
     * of country cleared it. Vajper's order 000008764 went out as Stockholm 11329 in Västmanlands
     * län for exactly that reason (PLIN-376).
     */
    public function testClearsARegionTheNewPostcodeHasLeftBehind(): void
    {
        $this->addressData = [
            'postcode' => '72132',
            'city' => 'Västerås',
            'region' => 'Västmanlands län',
            'region_id' => 1072,
        ];

        $address = $this->address();
        $address->expects(self::once())->method('setRegion')->with(null);
        $address->expects(self::once())->method('setRegionId')->with(null);

        self::assertTrue($this->converter->convert($this->qliroAddress(), $this->qliroCustomer(), $address));
    }

    /**
     * The same payload arriving twice moves no address, so the region it belongs to stays. A
     * merchant whose countries require a region would otherwise lose it on every callback.
     */
    public function testKeepsTheRegionWhenThePostcodeIsUnchanged(): void
    {
        $this->addressData = [
            'postcode' => '11122',
            'region' => 'Stockholms län',
            'region_id' => 1066,
        ];

        $address = $this->address();
        $address->expects(self::never())->method('setRegion');
        $address->expects(self::never())->method('setRegionId');

        $this->converter->convert($this->qliroAddress(), $this->qliroCustomer(), $address);
    }

    /**
     * A first address has no region to leave behind, and reporting a change for one that was
     * never there would push a pointless order update.
     */
    public function testKeepsQuietWhenThereIsNoRegionToClear(): void
    {
        $this->addressData = ['postcode' => '72132'];

        $address = $this->address();
        $address->expects(self::never())->method('setRegion');
        $address->expects(self::never())->method('setRegionId');

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

    /**
     * Qliro sends a B2B buyer as one string, `964969124 Gloppen Kommune`, and the order was
     * printed with the organisation number in front of the company on both addresses. Magento
     * has a field of its own for the number, so the two are stored apart.
     */
    public function testSplitsTheOrganisationNumberOutOfTheCompanyName(): void
    {
        $address = $this->address();

        self::assertTrue($this->converter->convert(
            $this->qliroAddress('964969124 Gloppen Kommune'),
            $this->qliroCustomer('964969124'),
            $address
        ));
        self::assertSame('Gloppen Kommune', $this->addressData['company']);
    }

    /**
     * The number itself is not written to the quote. `vat_id` on a quote address is what Magento
     * validates against VIES when automatic customer group assignment is on, and an organisation
     * number is not a VAT number, so the buyer would land in the group a store keeps for an
     * invalid one and pay that group's tax. The order is given the number instead.
     */
    public function testNeverWritesTheNumberToTheQuoteAddress(): void
    {
        $address = $this->address();

        $this->converter->convert(
            $this->qliroAddress('964969124 Gloppen Kommune'),
            $this->qliroCustomer('964969124'),
            $address
        );

        self::assertArrayNotHasKey('vat_id', $this->addressData);
    }

    /**
     * The number the order is given, which is what `Model\Order\OrganizationNumber` writes.
     */
    public function testReadsTheOrganisationNumberForTheOrder(): void
    {
        self::assertSame(
            '964969124',
            $this->converter->organizationNumber(
                $this->qliroAddress('964969124 Gloppen Kommune'),
                $this->qliroCustomer('964969124')
            )
        );
    }

    /**
     * A shorter number must not eat the front of a longer one: 964969124 against `9649691240 AB`
     * left `0 AB` behind as the company name.
     */
    public function testKeepsANameWhoseNumberOnlyStartsWithTheOneQliroSent(): void
    {
        $address = $this->address();

        $this->converter->convert(
            $this->qliroAddress('9649691240 AB'),
            $this->qliroCustomer('964969124'),
            $address
        );

        self::assertSame('9649691240 AB', $this->addressData['company']);
    }

    /**
     * The same, with the longer number written the way a country separates it: all the digits of
     * the shorter one are there, and a separator behind them is not the end of the number.
     */
    public function testKeepsANameWhoseSeparatedNumberOnlyStartsWithTheOneQliroSent(): void
    {
        $address = $this->address();

        $this->converter->convert(
            $this->qliroAddress('964969-1240 AB'),
            $this->qliroCustomer('964969124'),
            $address
        );

        self::assertSame('964969-1240 AB', $this->addressData['company']);
    }

    /**
     * The number is written with the separators the country uses, and the company name repeats
     * them or leaves them out. Only the digits decide whether the name starts with the number.
     */
    public function testSplitsTheNumberWhateverSeparatorsEitherSideCarries(): void
    {
        $address = $this->address();

        $this->converter->convert(
            $this->qliroAddress('556036-0793 Acme AB'),
            $this->qliroCustomer('5560360793'),
            $address
        );

        self::assertSame('Acme AB', $this->addressData['company']);
    }

    /**
     * Qliro sending the two apart is what the checkout is expected to do, and the day it does
     * the company name must survive untouched.
     */
    public function testKeepsACompanyNameThatDoesNotStartWithTheNumber(): void
    {
        $address = $this->address();

        $this->converter->convert(
            $this->qliroAddress('Gloppen Kommune'),
            $this->qliroCustomer('964969124'),
            $address
        );

        self::assertSame('Gloppen Kommune', $this->addressData['company']);
    }

    /**
     * A name that is nothing but the number is still the only name the order has, and an empty
     * company would announce the buyer to Qliro as a private person on the next update.
     */
    public function testKeepsACompanyNameThatIsOnlyTheNumber(): void
    {
        $address = $this->address();

        $this->converter->convert(
            $this->qliroAddress('964969124'),
            $this->qliroCustomer('964969124'),
            $address
        );

        self::assertSame('964969124', $this->addressData['company']);
    }

    /**
     * `VatNumber` is the field Qliro has for this. It is empty today, and where it is filled it
     * is the answer, not the personal number the checkout falls back to.
     */
    public function testPrefersTheVatNumberFieldWhereQliroFillsIt(): void
    {
        self::assertSame(
            'SE556036079301',
            $this->converter->organizationNumber(
                $this->qliroAddress('Acme AB'),
                $this->qliroCustomer('5560360793', 'SE556036079301')
            )
        );
    }

    /**
     * The number that answers for the order is not always the one the name was glued to: a
     * `VatNumber` carries the country prefix, the name carries the bare organisation number.
     */
    public function testStripsTheNumberTheNameCarriesEvenWhenVatIdComesFromTheOtherField(): void
    {
        $address = $this->address();

        $this->converter->convert(
            $this->qliroAddress('5560360793 Acme AB'),
            $this->qliroCustomer('5560360793', 'SE556036079301'),
            $address
        );

        self::assertSame('Acme AB', $this->addressData['company']);
        self::assertSame(
            'SE556036079301',
            $this->converter->organizationNumber(
                $this->qliroAddress('5560360793 Acme AB'),
                $this->qliroCustomer('5560360793', 'SE556036079301')
            )
        );
    }

    /**
     * For a buyer who is not a company the same field carries their own identity number, which
     * has no business on an order address at all.
     */
    public function testNeverWritesTheIdentityNumberOfAPrivateBuyer(): void
    {
        $address = $this->address();

        $this->converter->convert($this->qliroAddress(), $this->qliroCustomer('19800101-1234'), $address);

        self::assertArrayNotHasKey('vat_id', $this->addressData);
        self::assertNull(
            $this->converter->organizationNumber($this->qliroAddress(), $this->qliroCustomer('19800101-1234'))
        );
    }

    /**
     * Nothing could clear a company once it was on the quote: null values are skipped. A buyer
     * who starts as a company and completes as themselves kept it, and so did a quote that took
     * the store name from the shipping placeholder of a release before 1.7.26.
     */
    public function testClearsACompanyTheIdentifiedBuyerDoesNotHave(): void
    {
        $address = $this->address();
        $this->addressData['company'] = 'Batterigiganten AB';
        $this->addressData['vat_id'] = '5560360793';

        self::assertTrue(
            $this->converter->convert($this->qliroAddress(), $this->qliroCustomer(), $address)
        );
        self::assertNull($this->addressData['company']);

        // Whatever sits in `vat_id` was put there by the store, its own checkout form or an
        // address book, and Qliro saying the buyer is private does not make it ours to clear
        self::assertSame('5560360793', $this->addressData['vat_id']);
    }

    /**
     * Qliro masks the address, the company with it, until the buyer is identified. An empty
     * company name there says nothing about the buyer and must not wipe a company of their own.
     */
    public function testKeepsTheCompanyWhileTheBuyerIsNotIdentified(): void
    {
        $qliroAddress = $this->createMock(QliroOrderCustomerAddressInterface::class);
        $qliroAddress->method('getPostalCode')->willReturn(null);

        $address = $this->address();
        $this->addressData['company'] = 'Acme AB';

        self::assertFalse($this->converter->convert($qliroAddress, null, $address));
        self::assertSame('Acme AB', $this->addressData['company']);
    }

    /**
     * Clearing the company is a change like any other, so an address copied from the address
     * book stops pointing at it.
     */
    public function testDetachesFromTheCustomerAddressWhenTheCompanyIsCleared(): void
    {
        $address = $this->address();
        $address->method('getCustomerAddressId')->willReturn(7);
        $address->expects(self::once())->method('setCustomerAddressId')->with(null);
        $this->addressData['company'] = 'Batterigiganten AB';

        $this->converter->convert($this->qliroAddress(), $this->qliroCustomer(), $address);
    }

    private function qliroAddress(?string $company = null): QliroOrderCustomerAddressInterface&MockObject
    {
        $qliroAddress = $this->createMock(QliroOrderCustomerAddressInterface::class);
        $qliroAddress->method('getFirstName')->willReturn('Ada');
        $qliroAddress->method('getLastName')->willReturn('Lovelace');
        $qliroAddress->method('getStreet')->willReturn(['Sveavagen 1']);
        $qliroAddress->method('getCity')->willReturn('Stockholm');
        $qliroAddress->method('getPostalCode')->willReturn('11122');
        $qliroAddress->method('getCompanyName')->willReturn($company);

        return $qliroAddress;
    }

    /**
     * The interface has no `getVatNumber()`, so a customer with one is the container Qliro's
     * payload is mapped into, and a customer without one is anything implementing the interface.
     */
    private function qliroCustomer(
        ?string $personalNumber = null,
        ?string $vatNumber = null
    ): QliroOrderCustomerInterface&MockObject {
        $qliroCustomer = $this->createMock($vatNumber === null ? QliroOrderCustomerInterface::class : Customer::class);
        $qliroCustomer->method('getEmail')->willReturn('buyer@example.com');
        $qliroCustomer->method('getMobileNumber')->willReturn('0700000000');
        $qliroCustomer->method('getPersonalNumber')->willReturn($personalNumber);

        if ($vatNumber !== null) {
            $qliroCustomer->method('getVatNumber')->willReturn($vatNumber);
        }

        return $qliroCustomer;
    }
}
