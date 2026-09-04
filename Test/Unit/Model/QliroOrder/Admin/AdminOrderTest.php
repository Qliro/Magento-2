<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Admin;

use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\Admin\AdminOrder;
use Qliro\QliroOne\Model\QliroOrder\Admin\OrderPaymentTransaction;
use Qliro\QliroOne\Model\QliroOrder\PaymentMethod;

/**
 * The order management GetOrder response carries the same order level PaymentMethod as the
 * checkout one, and that is the method after routing has run (PLIN-324). The container had no
 * setter for it, and the mapper drops what it has no setter for, so it never reached the module.
 *
 * @see \Qliro\QliroOne\Model\QliroOrder\Admin\AdminOrder
 */
class AdminOrderTest extends TestCase
{
    private ContainerMapper $containerMapper;

    protected function setUp(): void
    {
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('create')->willReturnCallback(static function ($className) {
            return match (ltrim((string)$className, '\\')) {
                \Qliro\QliroOne\Api\Data\QliroOrderPaymentMethodInterface::class => new PaymentMethod(),
                \Qliro\QliroOne\Api\Data\AdminOrderPaymentTransactionInterface::class => new OrderPaymentTransaction(),
                default => null,
            };
        });

        $this->containerMapper = new ContainerMapper($objectManager, $this->createMock(LogManager::class));
    }

    public function testCarriesTheOrderLevelPaymentMethod(): void
    {
        /** @var AdminOrder $order */
        $order = $this->containerMapper->fromArray(
            [
                'OrderId' => 5510203,
                'PaymentMethod' => [
                    'PaymentMethodName' => 'Faktura 30 dagar',
                    'PaymentTypeCode' => 'QLIROPAYLATER_INVOICE30',
                ],
            ],
            new AdminOrder()
        );

        self::assertNotNull($order->getPaymentMethod());
        self::assertSame('Faktura 30 dagar', $order->getPaymentMethod()->getPaymentMethodName());
        self::assertSame('QLIROPAYLATER_INVOICE30', $order->getPaymentMethod()->getPaymentTypeCode());
    }

    /**
     * An order without one still maps, the rest of the payload is untouched.
     */
    public function testLeavesThePaymentMethodNullWhenTheOrderHasNone(): void
    {
        /** @var AdminOrder $order */
        $order = $this->containerMapper->fromArray(
            [
                'OrderId' => 5510203,
                'PaymentTransactions' => [
                    ['PaymentTransactionId' => 9200450, 'Type' => 'Preauthorization', 'Status' => 'Success'],
                ],
            ],
            new AdminOrder()
        );

        self::assertNull($order->getPaymentMethod());
        self::assertSame('Preauthorization', $order->getPaymentTransactions()[0]->getType());
    }
}
