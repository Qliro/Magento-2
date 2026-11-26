<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\MerchantPayment\Builder;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\MerchantPaymentCustomerInterface;
use Qliro\QliroOne\Api\Data\MerchantPaymentCustomerInterfaceFactory;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\MerchantPayment\Builder\CustomerBuilder;
use Qliro\QliroOne\Model\MerchantPayment\Customer;

/**
 * @see \Qliro\QliroOne\Model\MerchantPayment\Builder\CustomerBuilder::create
 */
class CustomerBuilderTest extends TestCase
{
    private const STORE_ID = 4;

    public function testSendsABuyerWithACompanyAsACompany(): void
    {
        self::assertSame(
            MerchantPaymentCustomerInterface::JURIDICAL_TYPE_COMPANY,
            $this->build('Test Company AB', false)->getJuridicalType()
        );
    }

    public function testSendsABuyerWithACompanyAsPrivateWhenOnlyPrivateCustomersAreAllowed(): void
    {
        self::assertSame(
            MerchantPaymentCustomerInterface::JURIDICAL_TYPE_PHYSICAL,
            $this->build('Test Company AB', true)->getJuridicalType()
        );
    }

    public function testSendsABuyerWithoutACompanyAsPrivate(): void
    {
        self::assertSame(
            MerchantPaymentCustomerInterface::JURIDICAL_TYPE_PHYSICAL,
            $this->build(null, false)->getJuridicalType()
        );
    }

    private function build(?string $company, bool $privateOnly): MerchantPaymentCustomerInterface
    {
        $address = $this->createMock(Address::class);
        $address->method('getCompany')->willReturn($company);
        $address->method('getTelephone')->willReturn('0740555089');

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getBillingAddress', 'getShippingAddress', 'getStoreId', 'getIsVirtual'])
            ->addMethods(['getCustomerEmail', 'getCustomerPersonalNumber'])
            ->getMock();
        $quote->method('getBillingAddress')->willReturn($address);
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getStoreId')->willReturn(self::STORE_ID);
        $quote->method('getCustomerEmail')->willReturn('buyer@example.com');

        $config = $this->createMock(Config::class);
        $config->method('isPrivateCustomersOnly')->with(self::STORE_ID)->willReturn($privateOnly);

        $factory = $this->createMock(MerchantPaymentCustomerInterfaceFactory::class);
        $factory->method('create')->willReturn(new Customer());

        $builder = new CustomerBuilder($factory, $config);
        $builder->setQuote($quote);

        return $builder->create();
    }
}
