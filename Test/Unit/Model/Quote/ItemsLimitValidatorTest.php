<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Quote;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\QliroOrder\Builder\OrderItemsBuilder;
use Qliro\QliroOne\Model\Quote\ItemsLimitValidator;

/**
 * @see \Qliro\QliroOne\Model\Quote\ItemsLimitValidator
 */
class ItemsLimitValidatorTest extends TestCase
{
    private OrderItemsBuilder&MockObject $orderItemsBuilder;
    private ItemsLimitValidator $validator;

    protected function setUp(): void
    {
        $this->orderItemsBuilder = $this->createMock(OrderItemsBuilder::class);
        $this->validator = new ItemsLimitValidator($this->orderItemsBuilder);
    }

    /**
     * A quote that was never persisted has nothing to send yet, so it is skipped
     * without building order lines.
     */
    public function testSkipsQuoteThatHasNoId(): void
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(null);

        $this->orderItemsBuilder->expects(self::never())->method('setQuote');

        $this->validator->validateQuoteItemsLimit($quote);
    }

    /**
     * The measure is the number of order LINES, not the summed quantity: one line whose
     * Quantity is far above the limit still passes. This is the regression the fix targets.
     */
    public function testAllowsHighQuantityCarriedOnASingleLine(): void
    {
        $this->givenBuilderReturnsLines(1);

        $this->validator->validateQuoteItemsLimit($this->persistedQuote());
    }

    /**
     * Exactly at the limit is allowed (boundary).
     */
    public function testAllowsExactlyTheLimit(): void
    {
        $this->givenBuilderReturnsLines(ItemsLimitValidator::MAX_ITEMS);

        $this->validator->validateQuoteItemsLimit($this->persistedQuote());
    }

    /**
     * One line over the limit is rejected.
     */
    public function testRejectsWhenLineCountExceedsTheLimit(): void
    {
        $this->givenBuilderReturnsLines(ItemsLimitValidator::MAX_ITEMS + 1);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage((string)ItemsLimitValidator::MAX_ITEMS);

        $this->validator->validateQuoteItemsLimit($this->persistedQuote());
    }

    private function persistedQuote(): Quote&MockObject
    {
        $quote = $this->createMock(Quote::class);
        $quote->method('getId')->willReturn(1);

        return $quote;
    }

    /**
     * Configure the builder to produce exactly $count outbound order lines. The validator
     * counts the array OrderItemsBuilder::create() returns, so the line shape is irrelevant.
     */
    private function givenBuilderReturnsLines(int $count): void
    {
        $lines = array_fill(0, $count, ['Type' => 'Product', 'MerchantReference' => 'sku']);
        $this->orderItemsBuilder->expects(self::once())->method('setQuote')->willReturnSelf();
        $this->orderItemsBuilder->expects(self::once())->method('create')->willReturn($lines);
    }
}
