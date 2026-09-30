<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Helper;

use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Helper\Data;

/**
 * formatPrice under strict types: DB strings still format, garbage still fails (PLIN-371)
 */
class DataFormatPriceTest extends TestCase
{
    private Data $helper;

    protected function setUp(): void
    {
        $this->helper = $this->getMockBuilder(Data::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
    }

    /**
     * @dataProvider prices
     */
    public function testFormatsWhatMagentoHandsOut($value, string $expected): void
    {
        $this->assertSame($expected, $this->helper->formatPrice($value));
    }

    public static function prices(): array
    {
        return [
            'DB decimal string' => ['1234.5000', '1234.50'],
            'float' => [1234.567, '1234.57'],
            'int' => [5, '5.00'],
            'null' => [null, '0.00'],
        ];
    }

    public function testANonNumericValueIsNotFormattedAsZero(): void
    {
        $this->expectException(\TypeError::class);

        $this->helper->formatPrice('abc');
    }
}
