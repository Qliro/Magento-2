<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder;

use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\QliroOrder\LineVatRate;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\LineVatRate
 */
class LineVatRateTest extends TestCase
{
    private LineVatRate $lineVatRate;

    protected function setUp(): void
    {
        $this->lineVatRate = new LineVatRate();
    }

    /**
     * @dataProvider pricesProvider
     */
    public function testDerivesTheRateFromThePricesTheLineCarries(
        float $incVat,
        float $exVat,
        float $expected
    ): void {
        self::assertSame($expected, $this->lineVatRate->fromPrices($incVat, $exVat));
    }

    /**
     * @return array<string, float[]>
     */
    public static function pricesProvider(): array
    {
        return [
            'the swedish rate' => [62.5, 50.0, 25.0],
            'the reduced rate' => [53.0, 50.0, 6.0],
            'a rate that does not land on whole ore is cut to two decimals' => [36.0, 29.0, 24.14],
            'no vat at all' => [50.0, 50.0, 0.0],
            'a line of nothing' => [0.0, 0.0, 0.0],
            'a discount line, sent negative on both fields' => [-62.5, -50.0, 25.0],
            'an ex vat amount of nothing cannot state a rate' => [12.5, 0.0, 0.0],
            'an inc vat amount below the ex vat one states none either' => [50.0, 62.5, 0.0],
        ];
    }

    /**
     * @dataProvider incVatAndRateProvider
     */
    public function testDerivesTheExVatAmountFromTheIncVatAmountAndTheRate(
        float $incVat,
        float $vatRate,
        float $expected
    ): void {
        self::assertSame($expected, $this->lineVatRate->exVatFromIncVat($incVat, $vatRate));
    }

    /**
     * @return array<string, float[]>
     */
    public static function incVatAndRateProvider(): array
    {
        return [
            'the swedish rate' => [62.5, 25.0, 50.0],
            'a refund line, sent negative' => [-125.0, 25.0, -100.0],
            'no rate leaves the amount as it is' => [-125.0, 0.0, -125.0],
            'an ex vat amount that does not land on whole ore is cut to two decimals' => [-99.99, 25.0, -79.99],
            'the reduced rate on an odd amount' => [-99.99, 6.0, -94.33],
            'the inc vat amount is rounded before it is divided' => [1.065, 6.0, 1.01],
        ];
    }
}
