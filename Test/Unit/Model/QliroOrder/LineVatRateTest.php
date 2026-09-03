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
}
