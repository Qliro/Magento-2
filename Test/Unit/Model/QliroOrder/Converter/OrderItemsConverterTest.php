<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Converter;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Fee;
use Qliro\QliroOne\Model\Product\Type\QuoteSourceProvider;
use Qliro\QliroOne\Model\Product\Type\TypePoolHandler;
use Qliro\QliroOne\Model\QliroOrder\Converter\OrderItemsConverter;

/**
 * The fee lines of the fetched Qliro order are what the Magento order is charged: they go into
 * qliroone_fees, and the grand total, the invoice, the credit memo and the capture all read that.
 *
 * @see \Qliro\QliroOne\Model\QliroOrder\Converter\OrderItemsConverter::convert
 */
class OrderItemsConverterTest extends TestCase
{
    private ContainerMapper&MockObject $containerMapper;
    private Payment&MockObject $payment;
    private Quote&MockObject $quote;
    private OrderItemsConverter $converter;

    protected function setUp(): void
    {
        $this->containerMapper = $this->createMock(ContainerMapper::class);
        $this->payment = $this->createMock(Payment::class);

        $this->quote = $this->createMock(Quote::class);
        $this->quote->method('isVirtual')->willReturn(true);
        $this->quote->method('getPayment')->willReturn($this->payment);

        $this->converter = new OrderItemsConverter(
            $this->createMock(TypePoolHandler::class),
            $this->createMock(Fee::class),
            $this->createMock(QuoteSourceProvider::class),
            $this->containerMapper
        );
    }

    /**
     * Both fee lines are booked. The assignment used to replace the whole array per line, so a
     * second fee left the order short by its amount, in the totals and in the capture alike.
     */
    public function testKeepsEveryFeeLine(): void
    {
        $invoiceFee = ['MerchantReference' => 'InvoiceFee', 'PricePerItemIncVat' => 29.0];
        $otherFee = ['MerchantReference' => 'OtherFee', 'PricePerItemIncVat' => 10.0];

        $this->containerMapper->method('toArray')->willReturnOnConsecutiveCalls($invoiceFee, $otherFee);

        $stored = null;
        $this->payment->expects(self::once())->method('setAdditionalInformation')
            ->willReturnCallback(function ($key, $value) use (&$stored) {
                self::assertSame('qliroone_fees', $key);
                $stored = $value;

                return $this->payment;
            });

        $this->converter->convert([$this->feeItem(), $this->feeItem()], $this->quote);

        self::assertSame([$invoiceFee, $otherFee], array_values($stored));
    }

    /**
     * An order without a fee line stores no fee, so a fee left over from an earlier conversion of
     * the same quote cannot be charged.
     */
    public function testStoresNoFeeWhenTheOrderCarriesNone(): void
    {
        $this->payment->expects(self::once())->method('setAdditionalInformation')
            ->with('qliroone_fees', []);

        $this->converter->convert([$this->discountItem()], $this->quote);
    }

    private function feeItem(): QliroOrderItemInterface&MockObject
    {
        $item = $this->createMock(QliroOrderItemInterface::class);
        $item->method('getType')->willReturn(QliroOrderItemInterface::TYPE_FEE);

        return $item;
    }

    private function discountItem(): QliroOrderItemInterface&MockObject
    {
        $item = $this->createMock(QliroOrderItemInterface::class);
        $item->method('getType')->willReturn(QliroOrderItemInterface::TYPE_DISCOUNT);

        return $item;
    }
}
