<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder;

use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote as QuoteModel;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Shipping\Model\Config as ShippingConfig;
use Magento\Store\Model\Information;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterface;
use Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterfaceFactory;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodBuilder;
use Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodsBuilder;

/**
 * What the log says when a quote produced no shipping method at all.
 *
 * @see \Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodsBuilder
 */
class ShippingMethodsBuilderDeclineLogTest extends TestCase
{
    private const QUOTE_ID = 283041;
    private const STORE_ID = 6;

    private LogManager&MockObject $logManager;
    private ShippingConfig&MockObject $shippingConfig;
    private QuoteAddress&MockObject $shippingAddress;
    private QuoteModel&MockObject $quote;
    private ShippingMethodsBuilder $builder;

    protected function setUp(): void
    {
        $this->logManager = $this->createMock(LogManager::class);
        $this->shippingConfig = $this->createMock(ShippingConfig::class);
        $this->shippingConfig->method('getActiveCarriers')->willReturn(['dhl' => null, 'freeshipping' => null]);

        // The DataObject setters below are magic, so they have to be added to the mock rather
        // than configured on it.
        $this->shippingAddress = $this->getMockBuilder(QuoteAddress::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'collectShippingRates',
                'getGroupedAllShippingRates',
                'getAllShippingRates',
                'getPostcode',
                'getCountryId',
                'getCity',
                'getStreetFull',
            ])
            ->addMethods(['setCollectShippingRates'])
            ->getMock();
        $this->shippingAddress->method('setCollectShippingRates')->willReturnSelf();
        $this->shippingAddress->method('collectShippingRates')->willReturnSelf();
        // No rates at all, which is the decline this test is about.
        $this->shippingAddress->method('getGroupedAllShippingRates')->willReturn([]);
        $this->shippingAddress->method('getAllShippingRates')->willReturn([]);
        $this->shippingAddress->method('getCity')->willReturn('København');
        $this->shippingAddress->method('getStreetFull')->willReturn('Nørregade 10');

        $store = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getCurrentCurrencyCode', 'getBaseCurrencyCode'])
            ->getMock();
        $store->method('getCurrentCurrencyCode')->willReturn('DKK');
        $store->method('getBaseCurrencyCode')->willReturn('SEK');

        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $this->quote = $this->getMockBuilder(QuoteModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId', 'getStoreId', 'getIsVirtual', 'getShippingAddress', 'collectTotals'])
            ->addMethods(['setTotalsCollectedFlag', 'getQuoteCurrencyCode'])
            ->getMock();
        $this->quote->method('getId')->willReturn(self::QUOTE_ID);
        $this->quote->method('getStoreId')->willReturn(self::STORE_ID);
        $this->quote->method('getIsVirtual')->willReturn(false);
        $this->quote->method('getShippingAddress')->willReturn($this->shippingAddress);
        $this->quote->method('getQuoteCurrencyCode')->willReturn('DKK');

        $responseFactory = $this->createMock(UpdateShippingMethodsResponseInterfaceFactory::class);
        $responseFactory->method('create')
            ->willReturn($this->createMock(UpdateShippingMethodsResponseInterface::class));

        $qliroConfig = $this->createMock(Config::class);
        $qliroConfig->method('isUnifaunEnabled')->willReturn(false);
        $qliroConfig->method('isIngridEnabled')->willReturn(false);

        $this->builder = new ShippingMethodsBuilder(
            $responseFactory,
            $this->createMock(ShippingMethodBuilder::class),
            $this->createMock(ManagerInterface::class),
            $storeManager,
            $qliroConfig,
            $this->logManager,
            $this->createMock(Information::class),
            $this->shippingConfig
        );
    }

    /**
     * Capture the context of the one decline the build produced
     *
     * @param string $level
     * @return array
     */
    private function declineContext(string $level): array
    {
        $captured = [];
        $this->logManager->method($level)
            ->willReturnCallback(function ($message, $context = []) use (&$captured): void {
                $captured = $context['extra'] ?? [];
            });

        $this->builder->setQuote($this->quote)->create();

        return $captured;
    }

    /**
     * The store view the quote was rated in has to be in the log. Without it the store view can
     * only be worked out from the language the product names came back in, and a carrier that is
     * fine in one store view and silent in another reads as an address problem.
     */
    public function testTheDeclineNamesTheStoreViewItWasRatedIn(): void
    {
        $this->shippingAddress->method('getPostcode')->willReturn('1165');
        $this->shippingAddress->method('getCountryId')->willReturn('DK');

        self::assertSame(self::STORE_ID, $this->declineContext('notice')['store_id'] ?? null);
    }

    /**
     * A rateable address that produced nothing is answered by the currencies and the carriers,
     * because a carrier reading the display currency while Magento denominates the amount it
     * rates on in the base currency returns nothing wherever the two differ.
     */
    public function testARateableAddressReportsTheCurrenciesAndTheCarriersItHad(): void
    {
        $this->shippingAddress->method('getPostcode')->willReturn('1165');
        $this->shippingAddress->method('getCountryId')->willReturn('DK');

        $context = $this->declineContext('notice');

        self::assertSame('DKK', $context['display_currency'] ?? null);
        self::assertSame('SEK', $context['base_currency'] ?? null);
        self::assertSame('DKK', $context['quote_currency'] ?? null);
        self::assertSame('dhl,freeshipping', $context['active_carriers'] ?? null);
    }

    /**
     * Whether the street and the city reached the quote, because a carrier can require them and
     * rate on nothing without them. The values themselves are the buyer's own and stay out.
     */
    public function testTheDeclineSaysWhetherTheStreetAndCityReachedTheQuote(): void
    {
        $this->shippingAddress->method('getPostcode')->willReturn('1165');
        $this->shippingAddress->method('getCountryId')->willReturn('DK');

        $context = $this->declineContext('notice');

        self::assertTrue($context['has_street'] ?? null);
        self::assertTrue($context['has_city'] ?? null);
        self::assertArrayNotHasKey('street', $context);
        self::assertArrayNotHasKey('city', $context);
    }

    /**
     * An address that cannot be rated yet is the normal state before the customer identifies, so
     * it stays at debug and must not pay for the carrier lookup that only a real problem needs.
     */
    public function testAnAddressThatCannotBeRatedYetStaysCheapAndAtDebug(): void
    {
        $this->shippingAddress->method('getPostcode')->willReturn(null);
        $this->shippingAddress->method('getCountryId')->willReturn('DK');

        $this->shippingConfig->expects(self::never())->method('getActiveCarriers');
        $this->logManager->expects(self::never())->method('notice');

        $context = $this->declineContext('debug');

        self::assertSame(self::STORE_ID, $context['store_id'] ?? null);
        self::assertArrayNotHasKey('active_carriers', $context);
    }

    /**
     * The scope lookup is diagnostics, so a failure in it reports itself and leaves the decline
     * alone. A logging line must never be what turns a decline into a critical.
     */
    public function testAFailingScopeLookupReportsItselfInsteadOfBreakingTheDecline(): void
    {
        $this->shippingAddress->method('getPostcode')->willReturn('1165');
        $this->shippingAddress->method('getCountryId')->willReturn('DK');
        $this->shippingConfig->method('getActiveCarriers')
            ->willThrowException(new \RuntimeException('carrier config exploded'));

        $context = $this->declineContext('notice');

        self::assertSame('carrier config exploded', $context['rating_scope_error'] ?? null);
        self::assertSame(self::STORE_ID, $context['store_id'] ?? null);
    }
}
