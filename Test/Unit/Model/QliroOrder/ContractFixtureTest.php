<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder;

use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder;
use Qliro\QliroOne\Model\QliroOrder\Admin\AdminOrder;
use Qliro\QliroOne\Model\QliroOrder\Admin\OrderItemAction;
use Qliro\QliroOne\Model\QliroOrder\Admin\OrderPaymentTransaction;
use Qliro\QliroOne\Model\QliroOrder\Customer;
use Qliro\QliroOne\Model\QliroOrder\Address\Address;
use Qliro\QliroOne\Model\QliroOrder\IdentityVerification;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\PaymentMethod;

/**
 * The module against the GetOrder payloads PIS pins from the Qliro sandbox, copied into
 * Test/Fixtures/qliro rather than written here, so what these assert is what Qliro sends.
 *
 * The point of the exercise, and the reason PLIN-374 reads the name: `PaymentMethodName` carries
 * the product, which is what the Ironman rollout renames, while `PaymentTypeCode` carries the
 * instrument behind it and collapses every pay later product to `INVOICE`.
 */
class ContractFixtureTest extends TestCase
{
    private ContainerMapper $containerMapper;

    protected function setUp(): void
    {
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('create')->willReturnCallback(static function ($className) {
            return match (ltrim((string)$className, '\\')) {
                \Qliro\QliroOne\Api\Data\QliroOrderPaymentMethodInterface::class => new PaymentMethod(),
                \Qliro\QliroOne\Api\Data\QliroOrderItemInterface::class => new Item(),
                \Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface::class => new Customer(),
                \Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterface::class => new Address(),
                \Qliro\QliroOne\Api\Data\QliroOrderIdentityVerificationInterface::class => new IdentityVerification(),
                \Qliro\QliroOne\Api\Data\AdminOrderPaymentTransactionInterface::class => new OrderPaymentTransaction(),
                \Qliro\QliroOne\Api\Data\AdminOrderItemActionInterface::class => new OrderItemAction(),
                default => null,
            };
        });

        $this->containerMapper = new ContainerMapper($objectManager, $this->createMock(LogManager::class));
    }

    /**
     * @dataProvider methodsOnTheWire
     */
    public function testReadsThePaymentMethodOfTheFixture(string $fixture, string $name, string $code): void
    {
        /** @var QliroOrder $order */
        $order = $this->containerMapper->fromArray($this->fixture($fixture), new QliroOrder());

        self::assertSame($name, $order->getPaymentMethod()->getPaymentMethodName());
        self::assertSame($code, $order->getPaymentMethod()->getPaymentTypeCode());
    }

    /**
     * The pay later products are told apart by the name only: the code says `INVOICE` for the
     * Ironman product and for the old one alike.
     */
    public static function methodsOnTheWire(): array
    {
        return [
            'card' => ['completed', 'CREDITCARDS', 'CREDITCARDS'],
            'invoice with a fee' => ['completed-with-fee', 'INVOICE', 'INVOICE'],
            'ironman pay later' => ['external-capture', 'QLIROPAYLATER_INVOICE14', 'INVOICE'],
            'qliro invoice' => ['platform-settled-shipping', 'QLIRO_INVOICE', 'INVOICE'],
        ];
    }

    /**
     * The fee line the module books onto the order, in the shape the fixture states it.
     */
    public function testReadsTheFeeLineOfTheFixture(): void
    {
        /** @var QliroOrder $order */
        $order = $this->containerMapper->fromArray($this->fixture('completed-with-fee'), new QliroOrder());

        $fees = array_filter(
            $order->getOrderItems(),
            static fn ($item) => $item->getType() === QliroOrderItemInterface::TYPE_FEE
        );

        self::assertCount(1, $fees);
        $fee = reset($fees);
        self::assertSame('qliro-invoice-fee', $fee->getMerchantReference());
        self::assertSame(1.99, $fee->getPricePerItemIncVat());
    }

    /**
     * Fields the module has no setter for are dropped rather than fatal, which is what lets Qliro
     * add to the payload without breaking every store. Both fixtures carry one on purpose.
     */
    public function testToleratesFieldsTheModuleDoesNotKnow(): void
    {
        $payload = $this->fixture('completed-with-fee');
        self::assertArrayHasKey('FutureField', $payload);

        /** @var QliroOrder $order */
        $order = $this->containerMapper->fromArray($payload, new QliroOrder());

        self::assertSame(5510202, $order->getOrderId());
        self::assertCount(3, $order->getOrderItems());
    }

    /**
     * The order management response of the same order: the method sits at order level, and the
     * transactions below it can name a different one each, which is the multi PSP case.
     */
    public function testReadsTheOrderLevelMethodOfAnOrderManagementResponse(): void
    {
        /** @var AdminOrder $order */
        $order = $this->containerMapper->fromArray($this->fixture('external-capture'), new AdminOrder());

        self::assertSame('QLIROPAYLATER_INVOICE14', $order->getPaymentMethod()->getPaymentMethodName());
        self::assertCount(2, $order->getPaymentTransactions());
        self::assertSame('Preauthorization', $order->getPaymentTransactions()[0]->getType());
        self::assertNull($order->getPaymentTransactions()[0]->getPaymentMethodName());
    }

    private function fixture(string $name): array
    {
        $path = sprintf('%s/../../../Fixtures/qliro/qliro-get-order-response.%s.v1.json', __DIR__, $name);

        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
