<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder;

use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\PaymentMethod;

/**
 * The module has no allow-list of payment methods: whatever Qliro reports on the order is
 * stored on the Magento order and shown as is. These pin that, because the Ironman rollout
 * (PLIN-374) adds six method codes and the plan rests on them needing no code change.
 *
 * @see \Qliro\QliroOne\Model\QliroOrder\PaymentMethod
 * @see \Qliro\QliroOne\Model\ContainerMapper
 */
class PaymentMethodTest extends TestCase
{
    private ContainerMapper $containerMapper;

    protected function setUp(): void
    {
        $this->containerMapper = new ContainerMapper(
            $this->createMock(ObjectManagerInterface::class),
            $this->createMock(LogManager::class)
        );
    }

    /**
     * @dataProvider ironmanMethods
     */
    public function testCarriesAnIronmanMethodThroughUnchanged(string $code, string $name): void
    {
        /** @var PaymentMethod $container */
        $container = $this->containerMapper->fromArray(
            ['PaymentTypeCode' => $code, 'PaymentMethodName' => $name],
            new PaymentMethod()
        );

        self::assertSame($code, $container->getPaymentTypeCode());
        self::assertSame($name, $container->getPaymentMethodName());
    }

    public static function ironmanMethods(): array
    {
        return [
            ['QLIROPAYLATER_INVOICE14', 'Faktura 14 dagar'],
            ['QLIROPAYLATER_INVOICE30', 'Faktura 30 dagar'],
            ['QLIROPAYLATER_BNPL', 'Betala senare'],
            ['QLIROPAYLATER_INVOICE30_60', 'Faktura 30/60'],
            ['QLIROPAYLATER_FLEXIBLE_PART_PAYMENT', 'Delbetalning flexibel'],
            ['QLIROPAYLATER_FIXED_PART_PAYMENT', 'Delbetalning fast'],
        ];
    }

    /**
     * The legacy codes keep working, both families run in parallel during the migration.
     */
    public function testCarriesALegacyMethodThroughUnchanged(): void
    {
        /** @var PaymentMethod $container */
        $container = $this->containerMapper->fromArray(
            ['PaymentTypeCode' => 'QLIRO_INVOICE', 'PaymentMethodName' => 'Faktura'],
            new PaymentMethod()
        );

        self::assertSame('QLIRO_INVOICE', $container->getPaymentTypeCode());
        self::assertSame('Faktura', $container->getPaymentMethodName());
    }

    /**
     * Anything the container has no setter for is dropped without a trace. That is why the
     * 999-prefixed subtype from PLIN-324 cannot reach Magento today: there is no field for it.
     */
    public function testDropsFieldsTheContainerDoesNotDeclare(): void
    {
        $container = $this->containerMapper->fromArray(
            [
                'PaymentTypeCode' => 'QLIROPAYLATER_FIXED_PART_PAYMENT',
                'PaymentMethodName' => 'Delbetalning fast',
                'PaymentMethodSubtype' => '999_SOMETHING',
            ],
            new PaymentMethod()
        );

        self::assertSame('QLIROPAYLATER_FIXED_PART_PAYMENT', $container->getPaymentTypeCode());
        self::assertObjectNotHasProperty('paymentMethodSubtype', $container);
    }
}
