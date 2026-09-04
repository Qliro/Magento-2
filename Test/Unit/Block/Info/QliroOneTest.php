<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Block\Info;

use Magento\Framework\View\Element\Template\Context;
use Magento\Payment\Model\Info;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Block\Info\QliroOne;
use Qliro\QliroOne\Model\PaymentMethodLabel;

/**
 * @see \Qliro\QliroOne\Block\Info\QliroOne
 */
class QliroOneTest extends TestCase
{
    private Info&MockObject $info;
    private QliroOne $block;

    protected function setUp(): void
    {
        $this->info = $this->createMock(Info::class);

        $context = $this->createMock(Context::class);
        $this->block = new QliroOne($context, new PaymentMethodLabel(), []);
        $this->block->setData('info', $this->info);
    }

    /**
     * The order view used to print the raw code, which for the QLIROPAYLATER family is not
     * something a merchant can read.
     */
    public function testPrefersTheNameQliroSent(): void
    {
        $this->givenAdditionalInformation([
            'qliro_payment_method_name' => 'Faktura 30 dagar',
            'qliro_payment_method_code' => 'QLIROPAYLATER_INVOICE30',
        ]);

        self::assertSame('Faktura 30 dagar', $this->block->getQliroMethodName());
    }

    /**
     * Older orders and any payload without a name still have to show something, and the code
     * is better than an empty cell.
     */
    public function testFallsBackToTheCode(): void
    {
        $this->givenAdditionalInformation([
            'qliro_payment_method_name' => null,
            'qliro_payment_method_code' => 'QLIROPAYLATER_INVOICE30',
        ]);

        self::assertSame('QLIROPAYLATER_INVOICE30', $this->block->getQliroMethodName());
    }

    /**
     * An empty string is treated the same as a missing name.
     */
    public function testFallsBackToTheCodeOnAnEmptyName(): void
    {
        $this->givenAdditionalInformation([
            'qliro_payment_method_name' => '',
            'qliro_payment_method_code' => 'QLIRO_INVOICE',
        ]);

        self::assertSame('QLIRO_INVOICE', $this->block->getQliroMethodName());
    }

    /**
     * Neither stored, for example an order placed before the module recorded them.
     */
    public function testReturnsAnEmptyStringWhenNeitherIsStored(): void
    {
        $this->givenAdditionalInformation([
            'qliro_payment_method_name' => null,
            'qliro_payment_method_code' => null,
        ]);

        self::assertSame('', $this->block->getQliroMethodName());
    }

    /**
     * The declared return type has to hold whatever additional_information carries, and it is
     * filled from a JSON payload the module does not control.
     */
    public function testCastsANonStringName(): void
    {
        $this->givenAdditionalInformation([
            'qliro_payment_method_name' => 30,
            'qliro_payment_method_code' => 'QLIROPAYLATER_INVOICE30',
        ]);

        self::assertSame('30', $this->block->getQliroMethodName());
    }

    /**
     * The raw code stays available for support, the admin view shows it in its own row.
     */
    public function testStillExposesTheRawCode(): void
    {
        $this->givenAdditionalInformation([
            'qliro_payment_method_name' => 'Faktura 30 dagar',
            'qliro_payment_method_code' => 'QLIROPAYLATER_INVOICE30',
        ]);

        self::assertSame('QLIROPAYLATER_INVOICE30', $this->block->getQliroMethod());
    }

    /**
     * What the order view prints: the wording, not the product identifier Qliro sends.
     */
    public function testLabelsTheMethodForTheOrderView(): void
    {
        $this->givenAdditionalInformation([
            'qliro_payment_method_name' => 'QLIROPAYLATER_INVOICE30',
            'qliro_payment_method_code' => 'INVOICE',
        ]);

        self::assertSame('Invoice, 30 days', $this->block->getQliroMethodLabel());
        // and the raw name is still worth a row of its own next to it
        self::assertTrue($this->block->hasQliroMethodLabel());
    }

    /**
     * A method the table has no wording for is printed as Qliro named it, and then repeating it
     * in a second row would add nothing.
     */
    public function testPrintsAnUnknownMethodAsQliroNamedIt(): void
    {
        $this->givenAdditionalInformation([
            'qliro_payment_method_name' => 'QLIROPAYLATER_SOMETHING_NEW',
            'qliro_payment_method_code' => 'INVOICE',
        ]);

        self::assertSame('QLIROPAYLATER_SOMETHING_NEW', $this->block->getQliroMethodLabel());
        self::assertFalse($this->block->hasQliroMethodLabel());
    }

    private function givenAdditionalInformation(array $values): void
    {
        $this->info->method('getAdditionalInformation')
            ->willReturnCallback(static fn ($key) => $values[$key] ?? null);
    }
}
