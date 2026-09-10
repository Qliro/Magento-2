<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Builder;

use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Fee;
use Qliro\QliroOne\Model\QliroOrder\Builder\FeeBuilder;
use Qliro\QliroOne\Model\QliroOrder\Item;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\Builder\FeeBuilder
 */
class FeeBuilderTest extends TestCase
{
    /**
     * The two amounts used to be assigned the wrong way round, so the fee line went out with the
     * inc VAT amount on the ex VAT field and with no rate at all. The rate is the one the fee is
     * taxed with, the rounded amounts 5.99 and 4.79 would read back as 25.05.
     */
    public function testBuildsTheFeeLineWithTheAmountsOnTheRightSideAndTheRateTheFeeIsTaxedWith(): void
    {
        $fee = $this->createMock(Fee::class);
        $fee->method('getQlirooneFeeInclTax')->willReturn(5.99);
        $fee->method('getQlirooneFeeExclTax')->willReturn(4.79);
        $fee->method('getQlirooneFeeVatRate')->willReturn(25.0);

        $line = $this->buildBuilder($fee)->setQuote($this->createMock(Quote::class))->create();

        self::assertSame(5.99, $line->getPricePerItemIncVat());
        self::assertSame(4.79, $line->getPricePerItemExVat());
        self::assertSame(25.0, $line->getVatRate());
        self::assertSame(QliroOrderItemInterface::TYPE_FEE, $line->getType());
        self::assertSame('qliroone_fee', $line->getMerchantReference());
    }

    public function testRefusesToBuildWithoutAQuote(): void
    {
        $this->expectException(\LogicException::class);

        $this->buildBuilder($this->createMock(Fee::class))->create();
    }

    private function buildBuilder(Fee $fee): FeeBuilder
    {
        $itemFactory = $this->createMock(QliroOrderItemInterfaceFactory::class);
        $itemFactory->method('create')->willReturnCallback(static fn(): Item => new Item());

        $qliroConfig = $this->createMock(Config::class);
        $qliroConfig->method('getFeeMerchantReference')->willReturn('qliroone_fee');

        return new FeeBuilder(
            $qliroConfig,
            $itemFactory,
            $fee,
            $this->createMock(ManagerInterface::class)
        );
    }
}
