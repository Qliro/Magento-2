<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Test\Unit\Model\QliroOrder\Admin;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Qliro\QliroOne\Model\QliroOrder\Admin\BundleChildren;

/**
 * Which order items a document charges, as CaptureCoverage compares an invoice with a shipment capture
 *
 * @see \Qliro\QliroOne\Model\QliroOrder\Admin\BundleChildren
 */
class BundleChildrenTest extends TestCase
{
    /**
     * An invoice holds the bundle row and the children, and the children are what was charged.
     */
    public function testCountsTheChildrenOfAnInvoicedBundleInPlaceOfItsRow(): void
    {
        $order = $this->order();

        self::assertSame(
            [2 => 1.0, 3 => 3.0, 4 => 1.0],
            (new BundleChildren())->chargedQuantities([1 => 1.0, 2 => 1.0, 3 => 3.0, 4 => 1.0], $order)
        );
    }

    /**
     * A shipment of the bundle shipped together saves its row only, so each child counts its share.
     */
    public function testCountsTheShareOfEachChildOfABundleShippedTogether(): void
    {
        $order = $this->order();

        self::assertSame([2 => 1.0, 3 => 3.0], (new BundleChildren())->chargedQuantities([1 => 1.0], $order));
    }

    /**
     * A child of anything else follows its parent, which is counted on its own.
     */
    public function testLeavesOutTheChildOfAConfigurable(): void
    {
        $order = $this->order();

        self::assertSame([5 => 1.0], (new BundleChildren())->chargedQuantities([5 => 1.0, 6 => 1.0], $order));
    }

    /**
     * Two bundles priced by their children, each one A and three B, a simple item and a configurable
     */
    private function order(): Order&MockObject
    {
        $bundle = $this->item(1, 'bundle', 2.0, null, true);
        $items = [
            $bundle,
            $this->item(2, 'simple', 2.0, 1),
            $this->item(3, 'simple', 6.0, 1),
            $this->item(4, 'simple', 1.0),
            $this->item(5, 'configurable', 1.0),
            $this->item(6, 'simple', 1.0, 5),
        ];
        $bundle->method('getChildrenItems')->willReturn([$items[1], $items[2]]);

        $order = $this->createMock(Order::class);
        $order->method('getItemById')->willReturnCallback(
            static function ($id) use ($items): ?OrderItem {
                foreach ($items as $item) {
                    if ((int)$item->getId() === (int)$id) {
                        return $item;
                    }
                }

                return null;
            }
        );

        return $order;
    }

    private function item(
        int $id,
        string $type,
        float $qtyOrdered,
        ?int $parentId = null,
        bool $childrenCalculated = false
    ): OrderItem&MockObject {
        $item = $this->createMock(OrderItem::class);
        $item->method('getId')->willReturn($id);
        $item->method('getProductType')->willReturn($type);
        $item->method('getQtyOrdered')->willReturn($qtyOrdered);
        $item->method('getParentItemId')->willReturn($parentId);
        $item->method('isChildrenCalculated')->willReturn($childrenCalculated);
        $item->method('isShipSeparately')->willReturn(false);

        return $item;
    }
}
