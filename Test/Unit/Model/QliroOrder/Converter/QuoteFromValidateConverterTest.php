<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Converter;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterface;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface;
use Qliro\QliroOne\Api\Data\ValidateOrderNotificationInterface;
use Qliro\QliroOne\Model\QliroOrder\Converter\AddressConverter;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromValidateConverter;

/**
 * Qliro masks the buyer's street and city until they identify, so a quote can reach the
 * validation callback with a country and a postcode and nothing else. The callback carries the
 * rest of the address, and it is the last chance to put it on the quote before the delivery
 * Qliro states is looked for among the rates (PLIN-376).
 *
 * @see QuoteFromValidateConverter
 */
class QuoteFromValidateConverterTest extends TestCase
{
    private AddressConverter&MockObject $addressConverter;
    private QuoteFromValidateConverter $converter;

    protected function setUp(): void
    {
        $this->addressConverter = $this->createMock(AddressConverter::class);
        $this->converter = new QuoteFromValidateConverter($this->addressConverter);
    }

    /**
     * The case the merchant's buyers hit: a physical order whose carrier answers only a complete
     * destination. Skipping the address here left seventeen checkouts unable to pay in ten days.
     */
    public function testAppliesTheAddressToAPhysicalQuote(): void
    {
        $qliroAddress = $this->createMock(QliroOrderCustomerAddressInterface::class);
        $customer = $this->createMock(QliroOrderCustomerInterface::class);
        $address = $this->createMock(Address::class);

        $this->addressConverter->expects(self::once())
            ->method('convert')
            ->with($qliroAddress, $customer, $address);

        $this->converter->convert(
            $this->request($qliroAddress, $customer),
            $this->quote($address, false)
        );
    }

    /**
     * A virtual quote keeps what it had: there is no delivery to correct, and the selection
     * Qliro states is written straight onto it because nothing else will.
     */
    public function testKeepsWritingTheMethodOnAVirtualQuote(): void
    {
        $qliroAddress = $this->createMock(QliroOrderCustomerAddressInterface::class);
        $customer = $this->createMock(QliroOrderCustomerInterface::class);
        $address = $this->address();
        $address->expects(self::once())->method('setShippingMethod')->with('dhl_pickup_A');

        $this->addressConverter->expects(self::once())->method('convert');

        $this->converter->convert(
            $this->request($qliroAddress, $customer),
            $this->quote($address, true)
        );
    }

    /**
     * A physical quote is not given the delivery here. Whether it may be applied at all is the
     * builder's decision, which only accepts a code the carriers still offer and a price the
     * store agrees with.
     */
    public function testDoesNotWriteTheMethodOnAPhysicalQuote(): void
    {
        $address = $this->address();
        $address->expects(self::never())->method('setShippingMethod');

        $this->converter->convert(
            $this->request(
                $this->createMock(QliroOrderCustomerAddressInterface::class),
                $this->createMock(QliroOrderCustomerInterface::class)
            ),
            $this->quote($address, false)
        );
    }

    /**
     * `setShippingMethod` is a magic method on the address, which PHPUnit cannot configure
     * unless it is named.
     *
     * @return Address&MockObject
     */
    private function address(): Address
    {
        return $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->addMethods(['setShippingMethod'])
            ->getMock();
    }

    /**
     * @param QliroOrderCustomerAddressInterface $qliroAddress
     * @param QliroOrderCustomerInterface $customer
     * @return ValidateOrderNotificationInterface&MockObject
     */
    private function request($qliroAddress, $customer): ValidateOrderNotificationInterface
    {
        $request = $this->createMock(ValidateOrderNotificationInterface::class);
        $request->method('getShippingAddress')->willReturn($qliroAddress);
        $request->method('getCustomer')->willReturn($customer);
        $request->method('getSelectedShippingMethod')->willReturn('dhl_pickup_A');

        return $request;
    }

    /**
     * @param Address $address
     * @param bool $isVirtual
     * @return Quote&MockObject
     */
    private function quote($address, bool $isVirtual): Quote
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('isVirtual')->willReturn($isVirtual);
        $quote->method('getShippingAddress')->willReturn($address);

        return $quote;
    }
}
