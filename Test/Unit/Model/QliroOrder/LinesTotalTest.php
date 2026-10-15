<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder;

use Magento\Framework\DataObject;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\QliroOrder\LinesTotal;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\LinesTotal
 */
class LinesTotalTest extends TestCase
{
    private LinesTotal $linesTotal;

    protected function setUp(): void
    {
        $this->linesTotal = new LinesTotal();
    }

    /**
     * The delivery and the invoice fee are Qliro's own lines on the order, so what the module
     * sends is the rest of the store's total.
     */
    public function testLeavesTheDeliveryAndTheFeeToQliro(): void
    {
        $quote = $this->buildQuote(false, 1000.0, ['shipping_incl_tax' => '59.0000', 'qliroone_fee' => '29.0000']);

        self::assertSame(912.0, $this->linesTotal->ofQuote($quote));
    }

    /**
     * A virtual cart has no delivery and carries its totals on the billing address.
     */
    public function testReadsAVirtualCartOffItsBillingAddress(): void
    {
        $quote = $this->buildQuote(true, 154.0, ['qliroone_fee' => '29.0000']);

        self::assertSame(125.0, $this->linesTotal->ofQuote($quote));
    }

    /**
     * @param bool $isVirtual
     * @param float $grandTotal
     * @param array<string, string> $totals
     * @return Quote&MockObject
     */
    private function buildQuote(bool $isVirtual, float $grandTotal, array $totals): Quote&MockObject
    {
        $address = new DataObject($totals);

        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isVirtual', 'getBillingAddress', 'getShippingAddress'])
            ->getMock();
        $quote->method('isVirtual')->willReturn($isVirtual);
        $quote->setData('grand_total', $grandTotal);
        $quote->method($isVirtual ? 'getBillingAddress' : 'getShippingAddress')->willReturn($address);

        if ($isVirtual) {
            $quote->expects(self::never())->method('getShippingAddress');
        }

        return $quote;
    }
}
