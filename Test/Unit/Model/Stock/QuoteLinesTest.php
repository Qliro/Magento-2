<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Stock;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item as QuoteItem;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Stock\QuoteLines;

/**
 * @see \Qliro\QliroOne\Model\Stock\QuoteLines
 */
class QuoteLinesTest extends TestCase
{
    /**
     * A line stands for its own quantity and its own type.
     */
    public function testReadsALineAsItStands(): void
    {
        $lines = (new QuoteLines())->fromQuote($this->buildQuote([$this->buildItem('sku-1', 3.0)]));

        self::assertSame(['sku-1' => ['qty' => 3.0, 'type' => 'simple']], $lines);
    }

    /**
     * A line with children in the cart holds no stock of its own, its children are in the same
     * list and hold it, and a child counts for its own quantity times its parent's.
     */
    public function testReadsACompositeLineThroughItsChildren(): void
    {
        $child = $this->buildItem('child-1', 2.0, 3.0);
        $parent = $this->buildItem('bundle-1-2', 3.0, null, [$child], 'bundle');

        $lines = (new QuoteLines())->fromQuote($this->buildQuote([$parent, $child]));

        self::assertSame(['child-1' => ['qty' => 6.0, 'type' => 'simple']], $lines);
    }

    /**
     * A child whose product was disabled after it was added is gone from the cart, and then the
     * parent is all that is left of the line, so it is what the line says.
     */
    public function testReadsACompositeLineWhoseChildrenLeftTheCart(): void
    {
        $child = $this->buildItem('child-1', 2.0, 3.0);
        $parent = $this->buildItem('conf-1', 3.0, null, [$child], 'configurable');

        $lines = (new QuoteLines())->fromQuote($this->buildQuote([$parent]));

        self::assertSame(['conf-1' => ['qty' => 3.0, 'type' => 'configurable']], $lines);
    }

    /**
     * The same sku on two lines is one quantity, because that is what the cart asks the stock for.
     */
    public function testAddsUpTheQuantityOfTheSameSkuOnTwoLines(): void
    {
        $lines = (new QuoteLines())->fromQuote(
            $this->buildQuote([$this->buildItem('sku-1', 2.0), $this->buildItem('sku-1', 3.0)])
        );

        self::assertSame(['sku-1' => ['qty' => 5.0, 'type' => 'simple']], $lines);
    }

    /**
     * A line with no sku says nothing the stock can answer.
     */
    public function testSkipsALineWithNoSku(): void
    {
        $lines = (new QuoteLines())->fromQuote($this->buildQuote([$this->buildItem('', 1.0)]));

        self::assertSame([], $lines);
    }

    /**
     * @param QuoteItem[] $items
     * @return Quote
     */
    private function buildQuote(array $items): Quote
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getAllItems')->willReturn($items);

        return $quote;
    }

    private function buildItem(
        string $sku,
        float $qty,
        ?float $parentQty = null,
        array $children = [],
        string $productType = 'simple'
    ): QuoteItem {
        $item = $this->createMock(QuoteItem::class);
        $item->method('getSku')->willReturn($sku);
        $item->method('getTotalQty')->willReturn($parentQty === null ? $qty : $qty * $parentQty);
        $item->method('getChildren')->willReturn($children);
        $item->method('getProductType')->willReturn($productType);

        return $item;
    }
}
