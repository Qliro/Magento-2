<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder;

use Magento\Directory\Model\Currency;
use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote as QuoteModel;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderShippingMethodInterface;
use Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterface;
use Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterfaceFactory;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodsBuilder;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodsBuilder
 */
class ShippingMethodsBuilderTest extends TestCase
{
    private const QUOTE_STORE_ID = 4;
    private const RATE_BASE_PRICE = 109.0;

    private Currency&MockObject $baseCurrency;
    private Store&MockObject $quoteStore;
    private StoreManagerInterface&MockObject $storeManager;
    private QuoteModel&MockObject $quote;
    private ShippingMethodsBuilder $builder;

    protected function setUp(): void
    {
        $this->baseCurrency = $this->createMock(Currency::class);
        $this->baseCurrency->method('convert')->willReturn(192.04);

        $this->quoteStore = $this->createMock(Store::class);
        $this->quoteStore->method('getBaseCurrency')->willReturn($this->baseCurrency);
        $this->quoteStore->method('getDefaultCurrencyCode')->willReturn('DKK');

        $this->storeManager = $this->createMock(StoreManagerInterface::class);

        // The DataObject getters and setters below are magic, so they have to be added to the
        // mock rather than configured on it.
        $rate = $this->getMockBuilder(Rate::class)
            ->disableOriginalConstructor()
            ->addMethods(['getCode', 'getPrice', 'setPrice'])
            ->getMock();
        $rate->method('getCode')->willReturn('dhl_international');
        $rate->method('getPrice')->willReturn(self::RATE_BASE_PRICE);

        $shippingAddress = $this->getMockBuilder(QuoteAddress::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['collectShippingRates', 'getGroupedAllShippingRates'])
            ->addMethods(['setCollectShippingRates'])
            ->getMock();
        $shippingAddress->method('setCollectShippingRates')->willReturnSelf();
        $shippingAddress->method('collectShippingRates')->willReturnSelf();
        $shippingAddress->method('getGroupedAllShippingRates')->willReturn([[$rate]]);

        $this->quote = $this->getMockBuilder(QuoteModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStoreId', 'getIsVirtual', 'getShippingAddress', 'collectTotals'])
            ->addMethods(['setTotalsCollectedFlag', 'getQuoteCurrencyCode'])
            ->getMock();
        $this->quote->method('getStoreId')->willReturn(self::QUOTE_STORE_ID);
        $this->quote->method('getIsVirtual')->willReturn(false);
        $this->quote->method('getShippingAddress')->willReturn($shippingAddress);

        $shippingMethod = $this->createMock(QliroOrderShippingMethodInterface::class);
        $shippingMethod->method('getMerchantReference')->willReturn('dhl_international');

        $shippingMethodBuilder = $this->createMock(ShippingMethodBuilder::class);
        $shippingMethodBuilder->method('setQuote')->willReturnSelf();
        $shippingMethodBuilder->method('setShippingRate')->willReturnSelf();
        $shippingMethodBuilder->method('create')->willReturn($shippingMethod);

        $responseFactory = $this->createMock(UpdateShippingMethodsResponseInterfaceFactory::class);
        $responseFactory->method('create')
            ->willReturn($this->createMock(UpdateShippingMethodsResponseInterface::class));

        $qliroConfig = $this->createMock(Config::class);
        $qliroConfig->method('isUnifaunEnabled')->willReturn(false);
        $qliroConfig->method('isIngridEnabled')->willReturn(false);

        $this->builder = new ShippingMethodsBuilder(
            $responseFactory,
            $shippingMethodBuilder,
            $this->createMock(ManagerInterface::class),
            $this->storeManager,
            $qliroConfig,
            $this->createMock(LogManager::class)
        );
    }

    /**
     * The `shippingMethods` callback carries no session, and its URL carries a store code only
     * when `web/url/use_store` is on, which Magento ships off, so by default it resolves to the
     * default store view. Reading the currency from there converts a Danish order's
     * delivery price with the Swedish store's rate, and Qliro charges the number it is given in
     * the currency the order was created with.
     */
    public function testConvertsTheRateIntoTheCurrencyOfTheQuote(): void
    {
        $this->storeManager->expects(self::once())
            ->method('getStore')
            ->with(self::QUOTE_STORE_ID)
            ->willReturn($this->quoteStore);
        $this->quote->method('getQuoteCurrencyCode')->willReturn('DKK');

        $this->baseCurrency->expects(self::once())
            ->method('convert')
            ->with(self::RATE_BASE_PRICE, 'DKK')
            ->willReturn(192.04);

        $this->builder->setQuote($this->quote)->create();
    }

    /**
     * A quote that never collected totals carries no currency code, and Currency::convert() throws
     * on an empty one rather than declining, which in the callback would turn into a critical and
     * a checkout with no delivery options at all.
     */
    public function testFallsBackToTheStoreCurrencyWhenTheQuoteCarriesNone(): void
    {
        $this->storeManager->method('getStore')->willReturn($this->quoteStore);
        $this->quote->method('getQuoteCurrencyCode')->willReturn(null);

        $this->baseCurrency->expects(self::once())
            ->method('convert')
            ->with(self::RATE_BASE_PRICE, 'DKK')
            ->willReturn(192.04);

        $this->builder->setQuote($this->quote)->create();
    }
}
