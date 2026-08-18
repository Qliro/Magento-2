<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Converter;

use Magento\Customer\Model\Data\Customer;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterface;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface;
use Qliro\QliroOne\Helper\Data as Helper;
use Qliro\QliroOne\Model\QliroOrder\Converter\AddressConverter;
use Qliro\QliroOne\Model\QliroOrder\Converter\CustomerConverter;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Converter\CustomerConverter
 */
class CustomerConverterTest extends TestCase
{
    private AddressConverter&MockObject $addressConverter;
    private Helper&MockObject $helper;
    private CustomerConverter $converter;

    protected function setUp(): void
    {
        $this->addressConverter = $this->createMock(AddressConverter::class);
        $this->helper = $this->createMock(Helper::class);
        $this->converter = new CustomerConverter($this->addressConverter, $this->helper);
    }

    /**
     * Qliro sends the address before the email is known. Skipping the address in that case
     * left the quote without a postcode, so shipping could not be rated. This is the
     * regression the fix targets.
     */
    public function testAppliesAddressWhenEmailIsMissing(): void
    {
        $qliroAddress = $this->createMock(QliroOrderCustomerAddressInterface::class);
        $qliroCustomer = $this->createMock(QliroOrderCustomerInterface::class);
        $qliroCustomer->method('getEmail')->willReturn(null);
        $qliroCustomer->method('getAddress')->willReturn($qliroAddress);

        $this->addressConverter->expects(self::exactly(2))->method('convert')->willReturn(true);

        self::assertTrue($this->converter->convert($qliroCustomer, $this->physicalQuote()));
    }

    /**
     * Both addresses are converted for a physical quote, and the shipping address is flagged
     * as same as billing according to the helper.
     */
    public function testConvertsBillingAndShippingAddress(): void
    {
        $qliroAddress = $this->createMock(QliroOrderCustomerAddressInterface::class);
        $qliroCustomer = $this->createMock(QliroOrderCustomerInterface::class);
        $qliroCustomer->method('getEmail')->willReturn('buyer@example.com');
        $qliroCustomer->method('getAddress')->willReturn($qliroAddress);

        $billingAddress = $this->createMock(Address::class);
        $shippingAddress = $this->createMock(Address::class);
        $quote = $this->physicalQuote($billingAddress, $shippingAddress);

        $this->helper->method('doAddressesMatch')->willReturn(true);
        $shippingAddress->expects(self::once())->method('setSameAsBilling')->with(true);

        $this->addressConverter->expects(self::exactly(2))->method('convert')->willReturn(true);

        self::assertTrue($this->converter->convert($qliroCustomer, $quote));
    }

    /**
     * A virtual quote has no shipping address to convert.
     */
    public function testSkipsShippingAddressForVirtualQuote(): void
    {
        $qliroAddress = $this->createMock(QliroOrderCustomerAddressInterface::class);
        $qliroCustomer = $this->createMock(QliroOrderCustomerInterface::class);
        $qliroCustomer->method('getEmail')->willReturn('buyer@example.com');
        $qliroCustomer->method('getAddress')->willReturn($qliroAddress);

        $quote = $this->createMock(Quote::class);
        $quote->method('getCustomer')->willReturn($this->createMock(Customer::class));
        $quote->method('isVirtual')->willReturn(true);
        $quote->method('getBillingAddress')->willReturn($this->createMock(Address::class));
        $quote->expects(self::never())->method('getShippingAddress');

        $this->addressConverter->expects(self::once())->method('convert')->willReturn(true);

        self::assertTrue($this->converter->convert($qliroCustomer, $quote));
    }

    /**
     * The email alone still counts as applied, even when no address came with it.
     */
    public function testAppliesEmailWithoutAddress(): void
    {
        $qliroCustomer = $this->createMock(QliroOrderCustomerInterface::class);
        $qliroCustomer->method('getEmail')->willReturn('buyer@example.com');
        $qliroCustomer->method('getAddress')->willReturn(null);

        $customer = $this->createMock(Customer::class);
        $customer->expects(self::once())->method('setData')->with('email', 'buyer@example.com');

        $quote = $this->createMock(Quote::class);
        $quote->method('getCustomer')->willReturn($customer);

        $this->addressConverter->expects(self::never())->method('convert');

        self::assertTrue($this->converter->convert($qliroCustomer, $quote));
    }

    /**
     * An empty payload changes nothing, which the caller reports back to the frontend.
     */
    public function testReportsNothingAppliedForEmptyPayload(): void
    {
        $qliroCustomer = $this->createMock(QliroOrderCustomerInterface::class);
        $qliroCustomer->method('getEmail')->willReturn(null);
        $qliroCustomer->method('getAddress')->willReturn(null);

        $quote = $this->createMock(Quote::class);

        self::assertFalse($this->converter->convert($qliroCustomer, $quote));
        self::assertFalse($this->converter->convert(null, $quote));
    }

    /**
     * An address the converter did not change does not count as applied either.
     */
    public function testReportsNothingAppliedWhenAddressIsUnchanged(): void
    {
        $qliroAddress = $this->createMock(QliroOrderCustomerAddressInterface::class);
        $qliroCustomer = $this->createMock(QliroOrderCustomerInterface::class);
        $qliroCustomer->method('getEmail')->willReturn(null);
        $qliroCustomer->method('getAddress')->willReturn($qliroAddress);

        $this->addressConverter->method('convert')->willReturn(false);

        self::assertFalse($this->converter->convert($qliroCustomer, $this->physicalQuote()));
    }

    private function physicalQuote(
        ?Address $billingAddress = null,
        ?Address $shippingAddress = null
    ): Quote&MockObject {
        $quote = $this->createMock(Quote::class);
        $quote->method('getCustomer')->willReturn($this->createMock(Customer::class));
        $quote->method('isVirtual')->willReturn(false);
        $quote->method('getBillingAddress')->willReturn($billingAddress ?? $this->createMock(Address::class));
        $quote->method('getShippingAddress')->willReturn($shippingAddress ?? $this->createMock(Address::class));

        return $quote;
    }
}
