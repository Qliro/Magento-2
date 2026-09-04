<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\PaymentMethodLabel;

/**
 * @see \Qliro\QliroOne\Model\PaymentMethodLabel
 */
class PaymentMethodLabelTest extends TestCase
{
    private PaymentMethodLabel $label;

    protected function setUp(): void
    {
        $this->label = new PaymentMethodLabel();
    }

    /**
     * The six of the Ironman rollout, which is the reason the table exists.
     *
     * @dataProvider ironmanMethods
     */
    public function testNamesTheIronmanProducts(string $name, string $expected): void
    {
        self::assertSame($expected, $this->label->getLabel($name));
    }

    public static function ironmanMethods(): array
    {
        return [
            ['QLIROPAYLATER_INVOICE14', 'Invoice, 14 days'],
            ['QLIROPAYLATER_INVOICE30', 'Invoice, 30 days'],
            ['QLIROPAYLATER_INVOICE30_60', 'Invoice, 30 to 60 days'],
            ['QLIROPAYLATER_BNPL', 'Pay later'],
            ['QLIROPAYLATER_FLEXIBLE_PART_PAYMENT', 'Part payment, flexible'],
            ['QLIROPAYLATER_FIXED_PART_PAYMENT', 'Part payment, fixed'],
        ];
    }

    /**
     * The products they are migrating from keep their own wording, so an order list spanning the
     * migration reads as two products rather than as one.
     */
    public function testNamesTheProductsBeingMigratedFrom(): void
    {
        self::assertSame('Qliro invoice', $this->label->getLabel('QLIRO_INVOICE'));
        self::assertSame('Qliro campaign', $this->label->getLabel('QLIRO_CAMPAIGN'));
        self::assertSame('Card', $this->label->getLabel('CREDITCARDS'));
    }

    /**
     * A method launched after this release is shown as Qliro named it. Anything else would turn a
     * new product into an empty cell on every order that used it.
     */
    public function testShowsAnUnknownMethodAsQliroNamedIt(): void
    {
        self::assertSame('QLIROPAYLATER_SOMETHING_NEW', $this->label->getLabel('QLIROPAYLATER_SOMETHING_NEW'));
        self::assertFalse($this->label->isKnown('QLIROPAYLATER_SOMETHING_NEW'));
        self::assertTrue($this->label->isKnown('QLIROPAYLATER_BNPL'));
    }

    /**
     * An order stored before the module recorded the name has the type code and nothing else.
     */
    public function testFallsBackToTheTypeCode(): void
    {
        self::assertSame('Qliro invoice', $this->label->getLabel(null, 'QLIRO_INVOICE'));
        self::assertSame('INVOICE', $this->label->getLabel('', 'INVOICE'));
        self::assertSame('', $this->label->getLabel(null, null));
    }
}
