<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder;

use Magento\Customer\Model\AddressFactory;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterfaceFactory;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerInterfaceFactory;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\QliroOrder\Address\Address as QliroAddress;
use Qliro\QliroOne\Model\QliroOrder\Builder\CustomerAddressBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\CustomerBuilder;
use Qliro\QliroOne\Model\QliroOrder\Customer;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Builder\CustomerBuilder::create
 *
 * PLIN-389: the juridical type is derived from the company on the quote address, and as a payment
 * method that address is the quote's own. A private buyer must reach Qliro as Physical, which they
 * did not while the preset shipping address left the store name in the company field.
 */
class CustomerBuilderTest extends TestCase
{
    private Quote&MockObject $quote;
    private Address&MockObject $shippingAddress;
    private CustomerBuilder $builder;

    protected function setUp(): void
    {
        $this->shippingAddress = $this->getMockBuilder(Address::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $this->quote = $this->createMock(Quote::class);
        $this->quote->method('getIsVirtual')->willReturn(false);
        $this->quote->method('getShippingAddress')->willReturn($this->shippingAddress);

        $customerAddressFactory = $this->createMock(QliroOrderCustomerAddressInterfaceFactory::class);
        $customerAddressFactory->method('create')->willReturnCallback(fn () => new QliroAddress());

        $customerFactory = $this->createMock(QliroOrderCustomerInterfaceFactory::class);
        $customerFactory->method('create')->willReturnCallback(fn () => new Customer());

        $qliroConfig = $this->createMock(Config::class);
        $qliroConfig->method('getShowAsPaymentMethod')->willReturn(true);

        $this->builder = new CustomerBuilder(
            $customerFactory,
            new CustomerAddressBuilder($customerAddressFactory),
            $this->createMock(AddressFactory::class),
            $qliroConfig
        );
    }

    /**
     * A guest checkout carries no company, and the buyer is a person.
     */
    public function testAPrivateBuyerIsPhysical(): void
    {
        $this->shippingAddress->addData([
            'firstname' => 'Kristine',
            'lastname' => 'Moen',
            'street' => 'Torsrudveien 10',
            'city' => 'Tranby',
            'postcode' => '3406',
        ]);

        $customer = $this->builder->setQuote($this->quote)->setCustomer(null)->create();

        self::assertSame(QliroOrderCustomerInterface::JURIDICAL_TYPE_PHYSICAL, $customer->getJuridicalType());
        self::assertNull($customer->getAddress()->getCompanyName());
    }

    /**
     * A company on the address is still what makes the buyer a company.
     */
    public function testACompanyOnTheAddressIsSentAsACompany(): void
    {
        $this->shippingAddress->addData([
            'firstname' => 'Kristine',
            'lastname' => 'Moen',
            'company' => 'Buyer AS',
            'street' => 'Torsrudveien 10',
            'city' => 'Tranby',
            'postcode' => '3406',
        ]);

        $customer = $this->builder->setQuote($this->quote)->setCustomer(null)->create();

        self::assertSame(QliroOrderCustomerInterface::JURIDICAL_TYPE_COMPANY, $customer->getJuridicalType());
        self::assertSame('Buyer AS', $customer->getAddress()->getCompanyName());
    }
}
