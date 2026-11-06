<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\Order;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\Order\ShippingProviderUpdate;

/**
 * @see \Qliro\QliroOne\Model\Order\ShippingProviderUpdate
 */
class ShippingProviderUpdateTest extends TestCase
{
    private const UNIFAUN = ['service' => ['name' => 'PostNord MyPack', 'id' => 'P19']];

    private AdapterInterface&MockObject $connection;
    private array $info = [];
    private ?string $description = null;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
    }

    public function testUnifaunUpdateGoesOnThePaymentAndTheDescription(): void
    {
        self::assertTrue($this->update()->apply($this->order(), 'Unifaun', self::UNIFAUN));

        self::assertSame(['provider' => 'Unifaun', 'payload' => self::UNIFAUN], $this->info['qliroone_shipping_info']);
        self::assertSame('Unifaun - PostNord MyPack (P19)', $this->description);
    }

    public function testIngridUpdateNamesTheCarrier(): void
    {
        $payload = ['session' => ['delivery_groups' => [['shipping' => [
            'carrier' => 'Budbee',
            'carrier_product_id' => 'budbee-home',
        ]]]]];

        $this->update()->apply($this->order(), 'Ingrid', $payload);

        self::assertSame('Ingrid - Budbee (budbee-home)', $this->description);
    }

    public function testProviderIsComparedWithoutCase(): void
    {
        $this->update()->apply($this->order(), 'unifaun', self::UNIFAUN);

        self::assertSame('unifaun - PostNord MyPack (P19)', $this->description);
    }

    public function testIncompletePayloadKeepsTheDescription(): void
    {
        $this->update()->apply($this->order(), 'Ingrid', ['session' => []]);

        self::assertNull($this->description);
        self::assertSame(['session' => []], $this->info['qliroone_shipping_info']['payload']);
    }

    public function testTheSameUpdateTwiceIsNotAppliedAgain(): void
    {
        $this->info = ['qliroone_shipping_info' => ['provider' => 'Unifaun', 'payload' => self::UNIFAUN]];

        self::assertFalse($this->update()->apply($this->order(), 'Unifaun', self::UNIFAUN));
        self::assertNull($this->description);
    }

    /**
     * An update held from before times were kept takes the time of its redelivery, so an older one
     * redelivered after it does not replace it
     */
    public function testRedeliveryOfAnUpdateHeldWithoutATimeKeepsItsTime(): void
    {
        $this->info = ['qliroone_shipping_info' => ['provider' => 'Unifaun', 'payload' => self::UNIFAUN]];

        self::assertTrue($this->update()->apply($this->order(), 'Unifaun', self::UNIFAUN, '2026-11-04T10:00:00+01:00'));
        self::assertSame('2026-11-04T10:00:00+01:00', $this->info['qliroone_shipping_info']['sent_at']);
        self::assertFalse(
            $this->update()->apply($this->order(), 'Unifaun', ['older' => true], '2026-11-04T09:00:00+01:00')
        );
    }

    public function testStoreKeepsTheLatestUpdateOfTheLink(): void
    {
        $this->connection->method('fetchOne')->willReturn('2026-11-04T10:00:00+01:00');
        $this->connection->expects(self::once())->method('insertOnDuplicate')->with(
            ShippingProviderUpdate::TABLE,
            self::callback(static fn (array $row) => $row['link_id'] === 7
                && $row['provider'] === 'Unifaun'
                && $row['payload'] === json_encode(self::UNIFAUN)
                && $row['sent_at'] === '2026-11-04T10:00:01+01:00'
                && (string)$row['created_at'] === 'NOW()'),
            // A replaced update counts its days from the newer one
            ['provider', 'payload', 'sent_at', 'created_at']
        );

        $this->update()->store(7, 'Unifaun', self::UNIFAUN, '2026-11-04T10:00:01+01:00');
    }

    /** Qliro keeps the Timestamp on a redelivery, which can come after a newer update */
    public function testStoreWithoutATimeKeepsTheStoredTime(): void
    {
        $this->connection->method('fetchOne')->willReturn('2026-11-04T10:00:00+01:00');
        $this->connection->expects(self::once())->method('insertOnDuplicate')
            ->with(self::anything(), self::callback(static fn (array $row) => $row['sent_at'] === '2026-11-04T10:00:00+01:00'));

        self::assertTrue($this->update()->store(7, 'Unifaun', self::UNIFAUN));
    }

    public function testRedeliveryOfAnOlderUpdateDoesNotReplaceTheStoredOne(): void
    {
        $this->connection->method('fetchOne')->willReturn('2026-11-04T10:00:01+01:00');
        $this->connection->expects(self::never())->method('insertOnDuplicate');

        $this->update()->store(7, 'Unifaun', self::UNIFAUN, '2026-11-04T09:00:00.5Z');
    }

    public function testRedeliveryOfAnOlderUpdateLeavesTheOrderAlone(): void
    {
        $newer = ['service' => ['name' => 'DHL', 'id' => 'D1']];
        $this->info = ['qliroone_shipping_info' => [
            'provider' => 'Unifaun', 'payload' => $newer, 'sent_at' => '2026-11-04T10:00:01+01:00',
        ]];

        self::assertFalse($this->update()->apply($this->order(), 'Unifaun', self::UNIFAUN, '2026-11-04T10:00:00+01:00'));
        self::assertSame($newer, $this->info['qliroone_shipping_info']['payload']);
    }

    public function testOtherShippingInfoIsKept(): void
    {
        $this->info = ['qliroone_shipping_info' => ['provider' => 'Unifaun', 'payload' => [], 'note' => 'kept']];

        $this->update()->apply($this->order(), 'Unifaun', self::UNIFAUN);

        self::assertSame('kept', $this->info['qliroone_shipping_info']['note']);
    }

    public function testUpdateWithoutATimeKeepsTheKnownTime(): void
    {
        $this->info = ['qliroone_shipping_info' => [
            'provider' => 'Unifaun', 'payload' => [], 'sent_at' => '2026-11-04T10:00:01+01:00',
        ]];

        self::assertTrue($this->update()->apply($this->order(), 'Unifaun', self::UNIFAUN));
        self::assertSame('2026-11-04T10:00:01+01:00', $this->info['qliroone_shipping_info']['sent_at']);
    }

    /** Else a late redelivery of a choice made in between would win over this one */
    public function testSameChoiceSentLaterMovesTheTimeOn(): void
    {
        $this->info = ['qliroone_shipping_info' => [
            'provider' => 'Unifaun', 'payload' => self::UNIFAUN, 'sent_at' => '2026-11-04T10:00:01+01:00',
        ]];

        self::assertTrue($this->update()->apply($this->order(), 'Unifaun', self::UNIFAUN, '2026-11-04T10:00:09+01:00'));
        self::assertSame('2026-11-04T10:00:09+01:00', $this->info['qliroone_shipping_info']['sent_at']);
        self::assertFalse($this->update()->apply(
            $this->order(), 'Unifaun', ['service' => ['name' => 'DHL', 'id' => 'D1']], '2026-11-04T10:00:05+01:00'
        ));
    }

    public function testNothingStoredLeavesTheOrderAlone(): void
    {
        $this->connection->method('fetchRow')->willReturn(false);
        $this->connection->expects(self::never())->method('delete');

        self::assertFalse($this->update()->applyStored($this->order(), 7));
        self::assertSame([], $this->info);
    }

    /** The caller saves the order and drops the row after, so a failed save keeps it */
    public function testStoredUpdateGoesOnTheOrderAndStaysUntilDropped(): void
    {
        $this->connection->method('fetchRow')
            ->willReturn(['provider' => 'Unifaun', 'payload' => json_encode(self::UNIFAUN), 'sent_at' => '']);
        $this->connection->expects(self::never())->method('delete');

        self::assertTrue($this->update()->applyStored($this->order(), 7));
        self::assertSame('Unifaun - PostNord MyPack (P19)', $this->description);
        self::assertSame(self::UNIFAUN, $this->info['qliroone_shipping_info']['payload']);
    }

    public function testUnreadableStoredUpdateIsDropped(): void
    {
        $this->connection->method('fetchRow')->willReturn(['provider' => 'Unifaun', 'payload' => '{"service":', 'sent_at' => '']);
        $this->connection->expects(self::once())->method('delete')
            ->with(ShippingProviderUpdate::TABLE, ['link_id = ?' => 7]);

        $this->expectException(\InvalidArgumentException::class);
        $this->update()->applyStored($this->order(), 7);
    }

    public function testDiscardDropsTheUpdateOfTheLink(): void
    {
        $this->connection->expects(self::once())->method('delete')
            ->with(ShippingProviderUpdate::TABLE, ['link_id = ?' => 7]);

        $this->update()->discard(7);
    }

    public function testPruneDropsUpdatesOlderThanTheDaysGiven(): void
    {
        $this->connection->expects(self::once())->method('delete')
            ->with(ShippingProviderUpdate::TABLE, self::callback(
                static fn (array $where) => (string)$where['created_at < ?'] === 'NOW() - INTERVAL 30 DAY'
            ))
            ->willReturn(2);

        self::assertSame(2, $this->update()->prune(30));
    }

    private function update(): ShippingProviderUpdate
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new ShippingProviderUpdate($resource, new Json());
    }

    private function order(): Order&MockObject
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getAdditionalInformation')->willReturnCallback(fn () => $this->info);
        $payment->method('setAdditionalInformation')->willReturnCallback(function (array $info) use ($payment) {
            $this->info = $info;

            return $payment;
        });

        $order = $this->createMock(Order::class);
        $order->method('getPayment')->willReturn($payment);
        $order->method('setShippingDescription')->willReturnCallback(function (string $text) use ($order) {
            $this->description = $text;

            return $order;
        });

        return $order;
    }
}
