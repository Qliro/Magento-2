<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder;

use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\QliroOrder\Item;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Item
 */
class ItemTest extends TestCase
{
    /**
     * The line itself refuses to carry a value the API would reject, so a builder that skips the
     * rounding cannot take the checkout down again, GitHub issue #122.
     */
    public function testRoundsTheRateAndBothAmountsOnTheWayIn(): void
    {
        $item = (new Item())
            ->setPricePerItemIncVat(5.9875)
            ->setPricePerItemExVat(4.790000000000001)
            ->setVatRate(24.137931034482758);

        self::assertSame(5.99, $item->getPricePerItemIncVat());
        self::assertSame(4.79, $item->getPricePerItemExVat());
        self::assertSame(24.14, $item->getVatRate());
    }

    /**
     * A discount line is sent negative on both amounts and keeps its sign.
     */
    public function testKeepsTheSignOfANegativeAmount(): void
    {
        $item = (new Item())
            ->setPricePerItemIncVat(-0.725)
            ->setPricePerItemExVat(-0.58);

        self::assertSame(-0.73, $item->getPricePerItemIncVat());
        self::assertSame(-0.58, $item->getPricePerItemExVat());
    }

    public function testKeepsValuesThatAlreadyFit(): void
    {
        $item = (new Item())
            ->setPricePerItemIncVat(62.5)
            ->setPricePerItemExVat(50.0)
            ->setVatRate(25.0);

        self::assertSame(62.5, $item->getPricePerItemIncVat());
        self::assertSame(50.0, $item->getPricePerItemExVat());
        self::assertSame(25.0, $item->getVatRate());
    }
}
