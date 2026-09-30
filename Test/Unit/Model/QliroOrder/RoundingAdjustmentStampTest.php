<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\RoundingAdjustment;
use Qliro\QliroOne\Model\QliroOrder\RoundingAdjustmentStamp;

/**
 * @see \Qliro\QliroOne\Model\QliroOrder\RoundingAdjustmentStamp
 */
class RoundingAdjustmentStampTest extends TestCase
{
    /**
     * What the capture replays is the line the order was reserved with, so it is read off the
     * reservation rather than recalculated from the cart later.
     */
    public function testWritesTheReservedLineOnThePayment(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->expects(self::once())
            ->method('setAdditionalInformation')
            ->with(
                Config::QLIROONE_ADDITIONAL_INFO_ROUNDING_ADJUSTMENT,
                ['incVat' => -0.25, 'exVat' => -0.2, 'vatRate' => 25.0, 'description' => 'Rounding']
            );

        $this->buildStamp()->record($this->buildQuote($payment), [$this->productLine(), $this->adjustmentLine()]);
    }

    /**
     * A reservation without such a line is one the capture must not send one for, and a failed
     * placement leaves the cart active with whatever the attempt before it wrote: the key is
     * cleared rather than left to ride to the order from an earlier reservation.
     */
    public function testClearsTheStampWhenTheReservationHoldsNoAdjustment(): void
    {
        $payment = $this->createMock(Payment::class);
        $payment->expects(self::never())->method('setAdditionalInformation');
        $payment->expects(self::once())
            ->method('unsAdditionalInformation')
            ->with(Config::QLIROONE_ADDITIONAL_INFO_ROUNDING_ADJUSTMENT);

        $this->buildStamp()->record($this->buildQuote($payment), [$this->productLine()]);
    }

    /**
     * A cart with no payment yet is nothing to write on.
     */
    public function testToleratesACartWithoutAPayment(): void
    {
        $this->expectNotToPerformAssertions();

        $this->buildStamp()->record($this->buildQuote(null), [$this->productLine()]);
    }

    /**
     * @return RoundingAdjustmentStamp
     */
    private function buildStamp(): RoundingAdjustmentStamp
    {
        $itemFactory = $this->createMock(QliroOrderItemInterfaceFactory::class);
        $itemFactory->method('create')->willReturnCallback(static fn(): Item => new Item());

        return new RoundingAdjustmentStamp(new RoundingAdjustment($itemFactory));
    }

    /**
     * @param Payment|null $payment
     * @return Quote&MockObject
     */
    private function buildQuote(?Payment $payment): Quote&MockObject
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPayment'])
            ->getMock();
        $quote->method('getPayment')->willReturn($payment);

        return $quote;
    }

    /**
     * @return QliroOrderItemInterface
     */
    private function productLine(): QliroOrderItemInterface
    {
        return (new Item())
            ->setMerchantReference('519:Kanalplast')
            ->setType(QliroOrderItemInterface::TYPE_PRODUCT)
            ->setQuantity(50)
            ->setPricePerItemIncVat(57.43)
            ->setVatRate(25.0);
    }

    /**
     * @return QliroOrderItemInterface
     */
    private function adjustmentLine(): QliroOrderItemInterface
    {
        return (new Item())
            ->setMerchantReference(RoundingAdjustment::MERCHANT_REFERENCE)
            ->setDescription('Rounding')
            ->setType(QliroOrderItemInterface::TYPE_DISCOUNT)
            ->setQuantity(1)
            ->setPricePerItemIncVat(-0.25)
            ->setPricePerItemExVat(-0.2)
            ->setVatRate(25.0);
    }
}
