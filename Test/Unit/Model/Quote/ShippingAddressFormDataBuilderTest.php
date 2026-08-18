<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Quote;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Quote\ShippingAddressFormDataBuilder;

/**
 * @see \Qliro\QliroOne\Model\Quote\ShippingAddressFormDataBuilder
 */
class ShippingAddressFormDataBuilderTest extends TestCase
{
    private ShippingAddressFormDataBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new ShippingAddressFormDataBuilder();
    }

    /**
     * The frontend feeds this straight into Magento's address converter, so the keys have to
     * match the checkout form field names and street stays a list of lines.
     */
    public function testBuildsTheFormDataForARatableAddress(): void
    {
        $address = $this->address([
            'postcode' => '11122',
            'country_id' => 'SE',
            'firstname' => 'Ada',
            'lastname' => 'Lovelace',
            'company' => 'Qliro',
            'street' => ['Sveavagen 1', 'Floor 3'],
            'city' => 'Stockholm',
            'region' => 'Stockholm',
            'region_id' => 264,
            'telephone' => '0700000000',
            'email' => 'buyer@example.com',
        ]);

        self::assertSame(
            [
                'firstname' => 'Ada',
                'lastname' => 'Lovelace',
                'company' => 'Qliro',
                'street' => ['Sveavagen 1', 'Floor 3'],
                'city' => 'Stockholm',
                'postcode' => '11122',
                'region' => 'Stockholm',
                'region_id' => 264,
                'country_id' => 'SE',
                'telephone' => '0700000000',
                'email' => 'buyer@example.com',
                'save_in_address_book' => 0,
            ],
            $this->builder->build($this->quote($address))
        );
    }

    /**
     * Magento cannot collect a rate without a postcode, and pushing such an address into the
     * client side quote is what produced the empty shipping method list in the first place.
     */
    public function testReturnsNullWithoutPostcode(): void
    {
        $address = $this->address(['postcode' => null, 'country_id' => 'SE']);

        self::assertNull($this->builder->build($this->quote($address)));
    }

    /**
     * Same for a missing country, collectShippingRates() bails out on it.
     */
    public function testReturnsNullWithoutCountry(): void
    {
        $address = $this->address(['postcode' => '11122', 'country_id' => null]);

        self::assertNull($this->builder->build($this->quote($address)));
    }

    /**
     * A virtual quote is never shipped, so there is nothing to send.
     */
    public function testReturnsNullForVirtualQuote(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('isVirtual')->willReturn(true);
        $quote->expects(self::never())->method('getShippingAddress');

        self::assertNull($this->builder->build($quote));
    }

    private function quote(Address $address): Quote&MockObject
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getShippingAddress')->willReturn($address);

        return $quote;
    }

    private function address(array $data): Address&MockObject
    {
        $address = $this->createMock(Address::class);
        $address->method('getPostcode')->willReturn($data['postcode'] ?? null);
        $address->method('getCountryId')->willReturn($data['country_id'] ?? null);
        $address->method('getFirstname')->willReturn($data['firstname'] ?? null);
        $address->method('getLastname')->willReturn($data['lastname'] ?? null);
        $address->method('getCompany')->willReturn($data['company'] ?? null);
        $address->method('getStreet')->willReturn($data['street'] ?? []);
        $address->method('getCity')->willReturn($data['city'] ?? null);
        $address->method('getRegion')->willReturn($data['region'] ?? null);
        $address->method('getRegionId')->willReturn($data['region_id'] ?? null);
        $address->method('getTelephone')->willReturn($data['telephone'] ?? null);
        $address->method('getEmail')->willReturn($data['email'] ?? null);

        return $address;
    }
}
