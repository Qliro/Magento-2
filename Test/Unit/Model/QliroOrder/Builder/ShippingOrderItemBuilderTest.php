<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder;

use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Tax\Api\TaxCalculationInterface;
use Magento\Tax\Helper\Data as TaxHelper;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Model\QliroOrder\Builder\ShippingOrderItemBuilder;
use Qliro\QliroOne\Model\QliroOrder\Item;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Builder\ShippingOrderItemBuilder
 */
class ShippingOrderItemBuilderTest extends TestCase
{
    /**
     * The line used to go out without a rate. The tax helper hands over rounded amounts, and
     * 5.99 over 4.79 would read back as 25.05, so the rate is the one shipping is taxed with.
     */
    public function testBuildsTheShippingLineWithTheRateShippingIsTaxedWith(): void
    {
        $line = $this->buildBuilder(5.99, 4.79)
            ->setQuote($this->buildQuote())
            ->create();

        self::assertSame(5.99, $line->getPricePerItemIncVat());
        self::assertSame(4.79, $line->getPricePerItemExVat());
        self::assertSame(25.0, $line->getVatRate());
        self::assertSame(QliroOrderItemInterface::TYPE_SHIPPING, $line->getType());
        self::assertSame('flatrate_flatrate', $line->getMerchantReference());
        self::assertSame('Fixed', $line->getDescription());
    }

    public function testRefusesToBuildWithoutAQuote(): void
    {
        $this->expectException(\LogicException::class);

        $this->buildBuilder(49.0, 39.2)->create();
    }

    private function buildBuilder(float $priceIncVat, float $priceExVat): ShippingOrderItemBuilder
    {
        $itemFactory = $this->createMock(QliroOrderItemInterfaceFactory::class);
        $itemFactory->method('create')->willReturnCallback(static fn(): Item => new Item());

        $taxHelper = $this->createMock(TaxHelper::class);
        $taxHelper->method('getShippingPrice')
            ->willReturnCallback(
                static fn($price, $includingTax): float => $includingTax ? $priceIncVat : $priceExVat
            );
        $taxHelper->method('getShippingTaxClass')->willReturn(2);

        $taxCalculation = $this->createMock(TaxCalculationInterface::class);
        $taxCalculation->method('getCalculatedRate')->with(2, null, 1)->willReturn(25.0);

        return new ShippingOrderItemBuilder(
            $itemFactory,
            $taxHelper,
            $this->createMock(ManagerInterface::class),
            $taxCalculation
        );
    }

    private function buildQuote(): Quote
    {
        $rate = $this->getMockBuilder(Rate::class)
            ->disableOriginalConstructor()
            ->addMethods(['getPrice', 'getMethodTitle'])
            ->getMock();
        $rate->method('getPrice')->willReturn(4.79);
        $rate->method('getMethodTitle')->willReturn('Fixed');

        $address = $this->createMock(Address::class);
        $address->method('getShippingMethod')->willReturn('flatrate_flatrate');
        $address->method('getShippingRateByCode')->willReturn($rate);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingAddress', 'getStoreId', 'getCustomerTaxClassId'])
            ->addMethods(['getCustomerId'])
            ->getMock();
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getCustomerId')->willReturn(null);

        return $quote;
    }
}
