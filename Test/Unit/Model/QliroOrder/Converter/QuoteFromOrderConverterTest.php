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
use Qliro\QliroOne\Api\Data\QliroOrderInterface;
use Qliro\QliroOne\Api\SubscriptionInterface;
use Qliro\QliroOne\Model\QliroOrder\Converter\AddressConverter;
use Qliro\QliroOne\Model\QliroOrder\Converter\CustomerConverter;
use Qliro\QliroOne\Model\QliroOrder\Converter\OrderItemsConverter;
use Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromOrderConverter;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Converter\QuoteFromOrderConverter
 */
class QuoteFromOrderConverterTest extends TestCase
{
    private CustomerConverter&MockObject $customerConverter;
    private AddressConverter&MockObject $addressConverter;
    private QuoteFromOrderConverter $converter;

    protected function setUp(): void
    {
        $this->customerConverter = $this->createMock(CustomerConverter::class);
        $this->addressConverter = $this->createMock(AddressConverter::class);
        $this->converter = new QuoteFromOrderConverter(
            $this->customerConverter,
            $this->addressConverter,
            $this->createMock(OrderItemsConverter::class),
            $this->createMock(SubscriptionInterface::class)
        );
    }

    /**
     * Qliro masks the address in the browser payload, so the fetched order is where it first
     * becomes known. The caller pushes the order update again on a true, which is what puts
     * the shipping methods into the checkout without a page reload.
     */
    public function testReportsTheAddressTheFetchedOrderBrought(): void
    {
        $this->customerConverter->method('convert')->willReturn(false);
        $this->addressConverter->method('convert')->willReturn(true);

        self::assertTrue($this->converter->convert($this->qliroOrder(), $this->physicalQuote()));
    }

    /**
     * A fetch that brought nothing new must report false, otherwise every checkout request
     * would push a pointless order update to Qliro.
     */
    public function testReportsNoChangeWhenTheOrderBroughtNothingNew(): void
    {
        $this->customerConverter->method('convert')->willReturn(false);
        $this->addressConverter->method('convert')->willReturn(false);

        self::assertFalse($this->converter->convert($this->qliroOrder(), $this->physicalQuote()));
    }

    /**
     * A change the customer converter made counts as well, it writes the billing and shipping
     * address when Qliro carries the address on the customer.
     */
    public function testReportsAChangeMadeByTheCustomerConverter(): void
    {
        $this->customerConverter->method('convert')->willReturn(true);
        $this->addressConverter->method('convert')->willReturn(false);

        self::assertTrue($this->converter->convert($this->qliroOrder(), $this->physicalQuote()));
    }

    /**
     * A virtual quote has no shipping address to convert.
     */
    public function testSkipsTheShippingAddressForVirtualQuote(): void
    {
        $this->customerConverter->method('convert')->willReturn(false);
        $this->addressConverter->expects(self::once())->method('convert')->willReturn(false);

        $quote = $this->createMock(Quote::class);
        $quote->method('isVirtual')->willReturn(true);
        $quote->method('getBillingAddress')->willReturn($this->createMock(Address::class));
        $quote->expects(self::never())->method('getShippingAddress');

        self::assertFalse($this->converter->convert($this->qliroOrder(), $quote));
    }

    private function qliroOrder(): QliroOrderInterface&MockObject
    {
        $qliroOrder = $this->createMock(QliroOrderInterface::class);
        $qliroOrder->method('getCustomer')->willReturn($this->createMock(QliroOrderCustomerInterface::class));
        $qliroOrder->method('getBillingAddress')
            ->willReturn($this->createMock(QliroOrderCustomerAddressInterface::class));
        $qliroOrder->method('getShippingAddress')
            ->willReturn($this->createMock(QliroOrderCustomerAddressInterface::class));
        $qliroOrder->method('getOrderItems')->willReturn([]);
        $qliroOrder->method('getSignupForNewsletter')->willReturn(false);

        return $qliroOrder;
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
