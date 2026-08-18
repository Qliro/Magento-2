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
use Qliro\QliroOne\Api\Data\UpdateShippingMethodsNotificationInterface;
use Qliro\QliroOne\Helper\Data as Helper;
use Qliro\QliroOne\Model\QliroOrder\Converter\AddressConverter;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromShippingMethodsConverter;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromShippingMethodsConverter
 */
class QuoteFromShippingMethodsConverterTest extends TestCase
{
    private AddressConverter&MockObject $addressConverter;
    private QuoteFromShippingMethodsConverter $converter;

    protected function setUp(): void
    {
        $this->addressConverter = $this->createMock(AddressConverter::class);
        $helper = $this->createMock(Helper::class);
        $this->converter = new QuoteFromShippingMethodsConverter($this->addressConverter, $helper);
    }

    /**
     * The shipping methods callback races the browser call that stores the address, so it has
     * to rate the address in its own payload. When Qliro carries it on the customer instead of
     * on ShippingAddress, that one is used, otherwise the callback answered with no methods.
     */
    public function testFallsBackToTheCustomerAddress(): void
    {
        $customerAddress = $this->createMock(QliroOrderCustomerAddressInterface::class);
        $customer = $this->createMock(QliroOrderCustomerInterface::class);
        $customer->method('getAddress')->willReturn($customerAddress);

        $container = $this->createMock(UpdateShippingMethodsNotificationInterface::class);
        $container->method('getShippingAddress')->willReturn(null);
        $container->method('getCustomer')->willReturn($customer);
        $container->method('getCountryCode')->willReturn('SE');

        $this->addressConverter->expects(self::exactly(2))
            ->method('convert')
            ->with($customerAddress, $customer, self::anything(), 'SE');

        $this->converter->convert($container, $this->physicalQuote());
    }

    /**
     * An explicit ShippingAddress stays authoritative.
     */
    public function testPrefersTheShippingAddressFromThePayload(): void
    {
        $shippingAddress = $this->createMock(QliroOrderCustomerAddressInterface::class);
        $customer = $this->createMock(QliroOrderCustomerInterface::class);
        $customer->expects(self::never())->method('getAddress');

        $container = $this->createMock(UpdateShippingMethodsNotificationInterface::class);
        $container->method('getShippingAddress')->willReturn($shippingAddress);
        $container->method('getCustomer')->willReturn($customer);
        $container->method('getCountryCode')->willReturn('SE');

        $this->addressConverter->expects(self::exactly(2))
            ->method('convert')
            ->with($shippingAddress, $customer, self::anything(), 'SE');

        $this->converter->convert($container, $this->physicalQuote());
    }

    /**
     * A payload without any address at all must not fatal, the builder declines afterwards.
     */
    public function testHandlesPayloadWithoutAnyAddress(): void
    {
        $container = $this->createMock(UpdateShippingMethodsNotificationInterface::class);
        $container->method('getShippingAddress')->willReturn(null);
        $container->method('getCustomer')->willReturn(null);
        $container->method('getCountryCode')->willReturn('SE');

        $this->addressConverter->expects(self::exactly(2))
            ->method('convert')
            ->with(null, null, self::anything(), 'SE');

        $this->converter->convert($container, $this->physicalQuote());
    }

    private function physicalQuote(): Quote&MockObject
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getBillingAddress')->willReturn($this->createMock(Address::class));
        $quote->method('getShippingAddress')->willReturn($this->createMock(Address::class));

        return $quote;
    }
}
