<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder;

use Magento\Directory\Model\Currency;
use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Store\Model\Store;
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

    /**
     * The rate is in base currency, and a SEK amount on a DKK order is charged as DKK.
     */
    public function testPricesTheLineInTheQuoteCurrency(): void
    {
        $prices = [];
        $builder = $this->buildBuilder(5.99, 4.79, $prices);

        $builder->setQuote($this->buildQuote(4.79, 'DKK'))->create();

        self::assertCount(2, $prices);
        self::assertEqualsWithDelta(3.353, $prices[0], 0.0001);
        self::assertEqualsWithDelta(3.353, $prices[1], 0.0001);
    }

    public function testRefusesToBuildWhenTheShippingMethodHasNoRate(): void
    {
        $this->expectException(\LogicException::class);

        $this->buildBuilder(49.0, 39.2)->setQuote($this->buildQuote(null))->create();
    }

    public function testRefusesToBuildWithoutAQuote(): void
    {
        $this->expectException(\LogicException::class);

        $this->buildBuilder(49.0, 39.2)->create();
    }

    private function buildBuilder(
        float $priceIncVat,
        float $priceExVat,
        array &$prices = []
    ): ShippingOrderItemBuilder {
        $itemFactory = $this->createMock(QliroOrderItemInterfaceFactory::class);
        $itemFactory->method('create')->willReturnCallback(static fn(): Item => new Item());

        $taxHelper = $this->createMock(TaxHelper::class);
        $taxHelper->method('getShippingPrice')
            ->willReturnCallback(
                static function ($price, $includingTax) use ($priceIncVat, $priceExVat, &$prices): float {
                    $prices[] = $price;

                    return $includingTax ? $priceIncVat : $priceExVat;
                }
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

    /**
     * @param float|null $ratePrice null for a quote whose shipping method has no collected rate
     * @param string $currencyCode the quote currency, the base one is SEK, 1 SEK is 0.7 DKK
     */
    private function buildQuote(?float $ratePrice = 4.79, string $currencyCode = 'SEK'): Quote
    {
        $rate = false;

        if ($ratePrice !== null) {
            $rate = $this->getMockBuilder(Rate::class)
                ->disableOriginalConstructor()
                ->addMethods(['getPrice', 'getMethodTitle'])
                ->getMock();
            $rate->method('getPrice')->willReturn($ratePrice);
            $rate->method('getMethodTitle')->willReturn('Fixed');
        }

        $address = $this->createMock(Address::class);
        $address->method('getShippingMethod')->willReturn('flatrate_flatrate');
        $address->method('getShippingRateByCode')->willReturn($rate);

        $baseCurrency = $this->createMock(Currency::class);
        $baseCurrency->method('convert')->willReturnCallback(
            static fn($price, $toCurrency): float => $toCurrency === 'DKK' ? $price * 0.7 : (float)$price
        );

        $store = $this->createMock(Store::class);
        $store->method('getBaseCurrency')->willReturn($baseCurrency);
        $store->method('getDefaultCurrencyCode')->willReturn('SEK');

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getShippingAddress', 'getStoreId', 'getStore', 'getCustomerTaxClassId'])
            ->addMethods(['getCustomerId', 'getQuoteCurrencyCode'])
            ->getMock();
        $quote->method('getShippingAddress')->willReturn($address);
        $quote->method('getStoreId')->willReturn(1);
        $quote->method('getStore')->willReturn($store);
        $quote->method('getCustomerId')->willReturn(null);
        $quote->method('getQuoteCurrencyCode')->willReturn($currencyCode);

        return $quote;
    }
}
