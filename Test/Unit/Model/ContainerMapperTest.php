<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model;

use Magento\Framework\ObjectManagerInterface;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Model\ContainerMapper;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\Notification\MerchantNotification;
use Qliro\QliroOne\Model\Notification\MerchantSavedCreditCard;
use Qliro\QliroOne\Model\QliroOrder\Item;
use Qliro\QliroOne\Model\QliroOrder\Shipment;

/**
 * The mapper is strict now, so it has to hand a typed setter the type it declares (PLIN-371)
 */
class ContainerMapperTest extends TestCase
{
    private ContainerMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ContainerMapper(
            $this->createMock(ObjectManagerInterface::class),
            $this->createMock(LogManager::class)
        );
    }

    public function testJsonNumberReachesAStringSetterAsString(): void
    {
        $card = $this->mapper->fromArray(
            ['OrderId' => 4711, 'CardBin' => 424242, 'ExpiryMonth' => 12],
            new MerchantSavedCreditCard()
        );

        $this->assertSame('4711', $card->getOrderId());
        $this->assertSame('424242', $card->getCardBin());
        $this->assertSame('12', $card->getExpiryMonth());
    }

    public function testNumericStringReachesAnIntSetterAsInt(): void
    {
        $fromString = $this->mapper->fromArray(['OrderId' => '4711'], new MerchantNotification());
        $fromFloat = $this->mapper->fromArray(['OrderId' => 99.0], new MerchantNotification());

        $this->assertSame(4711, $fromString->getOrderId());
        $this->assertSame(99, $fromFloat->getOrderId());
    }

    public function testNumericStringReachesAFloatSetterAsFloat(): void
    {
        $item = $this->mapper->fromArray(['PricePerItemIncVat' => '199.50'], new Item());

        $this->assertSame(199.5, $item->getPricePerItemIncVat());
    }

    public function testAFractionIsNotTruncatedIntoAnIntSetter(): void
    {
        $this->expectException(\TypeError::class);

        $this->mapper->fromArray(['OrderId' => '99.5'], new MerchantNotification());
    }

    /**
     * @dataProvider outOfRangeIds
     */
    public function testAnIdOutsideTheIntRangeIsNotSaturated($id): void
    {
        $this->expectException(\TypeError::class);

        $this->mapper->fromArray(['OrderId' => $id], new MerchantNotification());
    }

    public static function outOfRangeIds(): array
    {
        return [
            'string past PHP_INT_MAX' => ['9223372036854775808'],
            'JSON integer decoded as float' => [1e19],
            'infinity' => [INF],
        ];
    }

    public function testANumericKeyIsSkippedRatherThanFatal(): void
    {
        $notification = $this->mapper->fromArray([0 => 'x', 'OrderId' => 5], new MerchantNotification());

        $this->assertSame(5, $notification->getOrderId());
    }

    public function testAPayloadIsMappedIntoANewShipment(): void
    {
        $objectManager = $this->createMock(ObjectManagerInterface::class);
        $objectManager->method('create')->willReturnCallback(
            fn (string $type) => new ([
                Shipment::class => Shipment::class,
                QliroOrderItemInterface::class => Item::class,
            ][ltrim($type, '\\')])()
        );
        $mapper = new ContainerMapper($objectManager, $this->createMock(LogManager::class));

        $shipment = $mapper->fromArray(
            [
                'PaymentTransactionId' => 5,
                'OrderItems' => [
                    ['MerchantReference' => 'SKU-1', 'Quantity' => 2],
                    ['MerchantReference' => 'SKU-2', 'Quantity' => 1],
                ],
            ],
            Shipment::class
        );

        $items = $shipment->getOrderItems();
        $this->assertSame(5, $shipment->getPaymentTransactionId());
        $this->assertCount(2, $items);
        $this->assertContainsOnlyInstancesOf(Item::class, $items);
        $this->assertSame('SKU-1', $items[0]->getMerchantReference());
        $this->assertSame(2.0, $items[0]->getQuantity());
        $this->assertSame('SKU-2', $items[1]->getMerchantReference());
    }

    public function testANewShipmentHasNoOrderItems(): void
    {
        $this->assertSame([], (new Shipment())->getOrderItems());
    }
}
