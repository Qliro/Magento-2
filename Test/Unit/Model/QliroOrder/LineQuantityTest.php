<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\QliroOrder\LineQuantity;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\LineQuantity
 */
class LineQuantityTest extends TestCase
{
    private LineQuantity $lineQuantity;

    protected function setUp(): void
    {
        $this->lineQuantity = new LineQuantity();
    }

    /**
     * @dataProvider wholeQuantities
     */
    public function testAWholeQuantityIsCarried(float $quantity, int $expected): void
    {
        self::assertTrue($this->lineQuantity->isWhole($quantity));
        self::assertSame($expected, $this->lineQuantity->settlementQuantity($quantity, 'SKU-1'));
    }

    public static function wholeQuantities(): array
    {
        return [
            'one' => [1.0, 1],
            'many' => [12.0, 12],
            'none' => [0.0, 0],
            // A quantity is a decimal column and reaches here through a float, so three can
            // arrive as 2.9999999999999996. Casting that gives two
            'three as a float can hold' => [2.9999999999999996, 3],
            'three from the other side' => [3.0000000000000004, 3],
        ];
    }

    /**
     * @dataProvider fractionalQuantities
     */
    public function testAFractionalQuantityIsRefusedRatherThanTruncated(float $quantity): void
    {
        self::assertFalse($this->lineQuantity->isWhole($quantity));

        $this->expectException(LocalizedException::class);
        $this->lineQuantity->settlementQuantity($quantity, 'CABLE-5MM');
    }

    public static function fractionalQuantities(): array
    {
        return [
            // Truncating this one drops the line out of the payload entirely
            'half a metre' => [0.5],
            'two and a half' => [2.5],
            'a tenth over' => [1.1],
            // The smallest fraction `decimal(12,4)` can hold, and a real weight on a store
            // selling by the kilo. It must not round up into a whole kilo
            'a tenth of a gram under a kilo' => [0.9999],
        ];
    }

    /**
     * The merchant has to be able to find the line, so the refusal names it.
     */
    public function testTheRefusalNamesTheLineAndItsQuantity(): void
    {
        try {
            $this->lineQuantity->settlementQuantity(1.5, 'CABLE-5MM');
            self::fail('A fractional quantity has to be refused.');
        } catch (LocalizedException $exception) {
            self::assertStringContainsString('CABLE-5MM', $exception->getMessage());
            self::assertStringContainsString('1.5', $exception->getMessage());
        }
    }

    /**
     * The wire quantity is rounded, never cast: the cast is what turns three into two.
     */
    public function testTheWireQuantityIsRoundedRatherThanCast(): void
    {
        self::assertSame(3, $this->lineQuantity->toWire(2.9999999999999996));
        self::assertSame(2, $this->lineQuantity->toWire(2.0));
    }
}
