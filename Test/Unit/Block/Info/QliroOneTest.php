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
        $this->block = new QliroOne($context, []);
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

    private function givenAdditionalInformation(array $values): void
    {
        $this->info->method('getAdditionalInformation')
            ->willReturnCallback(static fn ($key) => $values[$key] ?? null);
    }
}
