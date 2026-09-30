<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder;

use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterfaceFactory;
use Qliro\QliroOne\Model\QliroOrder\Address\Address as QliroAddress;
use Qliro\QliroOne\Model\QliroOrder\Builder\CustomerAddressBuilder;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Builder\CustomerAddressBuilder
 */
class CustomerAddressBuilderTest extends TestCase
{
    /**
     * The c/o line the converter writes goes back to Qliro in its own field, not glued to the street.
     */
    public function testSendsTheCareOfLineAsCareOf(): void
    {
        $qliroAddress = $this->build(['Sveavagen 1', 'c/o Rosi Röckl']);

        self::assertSame('Sveavagen 1', $qliroAddress->getStreet());
        self::assertSame('Rosi Röckl', $qliroAddress->getCareOf());
    }

    public function testKeepsAnAddressWithoutCareOfAsItWas(): void
    {
        $qliroAddress = $this->build(['Sveavagen 1', 'Lgh 1102']);

        self::assertSame('Sveavagen 1; Lgh 1102', $qliroAddress->getStreet());
        self::assertNull($qliroAddress->getCareOf());
    }

    /**
     * The first line is the street, whatever it starts with.
     */
    public function testNeverReadsTheFirstLineAsCareOf(): void
    {
        $qliroAddress = $this->build(['c/o Rosi Röckl']);

        self::assertSame('c/o Rosi Röckl', $qliroAddress->getStreet());
        self::assertNull($qliroAddress->getCareOf());
    }

    private function build(array $street): QliroAddress
    {
        $factory = $this->createMock(QliroOrderCustomerAddressInterfaceFactory::class);
        $factory->method('create')->willReturn(new QliroAddress());

        $address = $this->createMock(Address::class);
        $address->method('getStreet')->willReturn($street);
        $address->method('getPostcode')->willReturn('111 22');

        return (new CustomerAddressBuilder($factory))->setAddress($address)->create();
    }
}
