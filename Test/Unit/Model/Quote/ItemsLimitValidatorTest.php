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
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\Builder\OrderItemsBuilder;
use Qliro\QliroOne\Model\Quote\ItemsLimitValidator;

/**
 * @see \Qliro\QliroOne\Model\Quote\ItemsLimitValidator
 */
class ItemsLimitValidatorTest extends TestCase
{
    private LogManager&MockObject $logManager;
    private OrderItemsBuilder&MockObject $orderItemsBuilder;
    private ItemsLimitValidator $validator;

    protected function setUp(): void
    {
        $this->logManager = $this->createMock(LogManager::class);
        $this->orderItemsBuilder = $this->createMock(OrderItemsBuilder::class);
        $this->validator = new ItemsLimitValidator($this->logManager, $this->orderItemsBuilder);
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
        $this->logManager->expects(self::never())->method('warning');

        $this->validator->validateQuoteItemsLimit($quote);
    }

    /**
     * The measure is the number of order LINES, not the summed quantity: one line whose
     * Quantity is far above the limit still passes. This is the regression the fix targets.
     */
    public function testAllowsHighQuantityCarriedOnASingleLine(): void
    {
        $this->givenBuilderReturnsLines(1);
        $this->logManager->expects(self::never())->method('warning');

        $this->validator->validateQuoteItemsLimit($this->persistedQuote());
    }

    /**
     * Exactly at the limit is allowed (boundary).
     */
    public function testAllowsExactlyTheLimit(): void
    {
        $this->givenBuilderReturnsLines(ItemsLimitValidator::MAX_ITEMS);
        $this->logManager->expects(self::never())->method('warning');

        $this->validator->validateQuoteItemsLimit($this->persistedQuote());
    }

    /**
     * One line over the limit is rejected, and the breach is logged as a structured warning
     * carrying the real line count and the limit.
     */
    public function testRejectsAndLogsWhenLineCountExceedsTheLimit(): void
    {
        $this->givenBuilderReturnsLines(ItemsLimitValidator::MAX_ITEMS + 1);

        $this->logManager->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('item-limit exceeded'),
                self::callback(
                    static fn (array $context): bool =>
                        ($context['extra']['line_count'] ?? null) === ItemsLimitValidator::MAX_ITEMS + 1
                        && ($context['extra']['limit'] ?? null) === ItemsLimitValidator::MAX_ITEMS
                )
            );

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
        $this->orderItemsBuilder->method('setQuote')->willReturnSelf();
        $this->orderItemsBuilder->method('create')->willReturn($lines);
    }
}
