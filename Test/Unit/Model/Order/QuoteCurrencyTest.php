<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Order;

use Magento\Directory\Model\Currency;
use Magento\Directory\Model\CurrencyFactory;
use Magento\Framework\App\Http\Context as HttpContext;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\Store;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Order\QuoteCurrency;

final class QuoteCurrencyTest extends TestCase
{
    private Currency $base;

    /** @var array<string, mixed> what the pin wrote on the store */
    private array $storeData = [];

    /** @var array<string, mixed> what the pin wrote on the HTTP context */
    private array $context = [];

    protected function setUp(): void
    {
        // Real model: load, setRates and getRate stay in memory while the rate is known.
        $this->base = $this->currency('SEK');
    }

    public function testQuoteKeepsItsCurrencyAndRateInAPush(): void
    {
        $this->base->setRates(['NOK' => 1.10, 'EUR' => 0.087]);
        $quote = $this->quote('NOK', 0.95);

        $this->pinner()->pin($quote);

        $current = $this->storeData['current_currency'];
        self::assertSame('NOK', $current->getCode());
        self::assertSame($current, $quote->getData('forced_currency'));
        self::assertSame(['current_currency' => 'NOK'], $this->context);
        self::assertSame(0.95, $this->base->getRate('NOK'));
        self::assertSame(0.087, $this->base->getRate('EUR'));
    }

    public function testTodaysFullRateIsKeptWhenItRoundsToTheQuotesRate(): void
    {
        $this->base->setRates(['EUR' => 0.087123456789]);

        $this->pinner()->pin($this->quote('EUR', 0.0871));

        self::assertSame(0.087123456789, $this->base->getRate('EUR'));
    }

    public function testSingleCurrencyQuoteIsPinnedToItsOwnCurrency(): void
    {
        $this->base->setRates(['SEK' => 1.0]);
        $quote = $this->quote('SEK', 1.0);

        $this->pinner()->pin($quote);

        self::assertSame('SEK', $quote->getData('forced_currency')->getCode());
        self::assertSame(1.0, $this->base->getRate('SEK'));
    }

    /** @return array<string, array{string|null, float|null, string}> */
    public static function unusableQuotes(): array
    {
        return [
            'no currency' => [null, 1.0, 'SEK'],
            'no rate' => ['NOK', null, 'SEK'],
            'zero rate' => ['NOK', 0.0, 'SEK'],
            'store base changed since' => ['NOK', 0.95, 'EUR'],
        ];
    }

    /** @dataProvider unusableQuotes */
    public function testQuoteThatCannotBePinnedIsLeftToMagento(?string $code, ?float $rate, string $quoteBase): void
    {
        $this->base->setRates(['NOK' => 1.10]);
        $quote = $this->quote($code, $rate, $quoteBase);

        $this->pinner()->pin($quote);

        self::assertSame([], $this->storeData);
        self::assertSame([], $this->context);
        self::assertSame(['NOK' => 1.10], $this->base->getRates());
        self::assertFalse($quote->hasData('forced_currency'));
    }

    private function pinner(): QuoteCurrency
    {
        $context = $this->createMock(HttpContext::class);
        $context->method('setValue')->willReturnCallback(function (string $name, mixed $value) use ($context) {
            $this->context[$name] = $value;

            return $context;
        });

        $factory = $this->createMock(CurrencyFactory::class);
        $factory->method('create')->willReturnCallback(fn () => $this->currency(''));

        return new QuoteCurrency($factory, $context);
    }

    private function currency(string $code): Currency
    {
        /** @var Currency $currency */
        $currency = (new \ReflectionClass(Currency::class))->newInstanceWithoutConstructor();
        $currency->setData('currency_code', $code);

        return $currency;
    }

    private function quote(?string $code, ?float $rate, string $base = 'SEK'): Quote
    {
        $store = $this->createMock(Store::class);
        $store->method('getBaseCurrency')->willReturn($this->base);
        $store->method('setData')->willReturnCallback(function (string $key, mixed $value) use ($store) {
            $this->storeData[$key] = $value;

            return $store;
        });

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getStore'])
            ->getMock();
        $quote->method('getStore')->willReturn($store);
        $quote->setData(['quote_currency_code' => $code, 'base_to_quote_rate' => $rate, 'base_currency_code' => $base]);

        return $quote;
    }
}
