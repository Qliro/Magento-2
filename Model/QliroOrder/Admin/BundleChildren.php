<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin;

use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Item as OrderItem;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Model\Config;

/**
 * A bundle priced by its children, which the checkout sends as a line at zero and a line per child
 *
 * The children carry the price, so a capture has to carry them or it charges nothing for the bundle.
 */
class BundleChildren
{
    /**
     * Whether the item is a bundle priced by its children
     *
     * @param OrderItem|null $item
     * @return bool
     */
    public function isPricedByChildren(?OrderItem $item): bool
    {
        return $item !== null && $item->getProductType() === BundleType::TYPE_CODE && $item->isChildrenCalculated();
    }

    /**
     * Whether the item is a child of a bundle priced by its children
     *
     * @param OrderItem $item
     * @param Order $order
     * @return bool
     */
    public function isPricedChild(OrderItem $item, Order $order): bool
    {
        return $item->getParentItemId() && $this->isPricedByChildren($order->getItemById($item->getParentItemId()));
    }

    /**
     * The children a shipment of such a bundle leaves out when it ships them together, with the quantity it ships
     *
     * Magento keeps only the bundle row in that shipment, so each child ships its share of the bundle quantity.
     *
     * @param OrderItem $bundle
     * @param float $bundleQty
     * @return array<int, array{0: OrderItem, 1: float}>
     */
    public function shippedTogether(OrderItem $bundle, float $bundleQty): array
    {
        return $bundle->isShipSeparately() ? [] : $this->shares($bundle, $bundleQty);
    }

    /**
     * Each child's share of a quantity of the bundle
     *
     * @param OrderItem $bundle
     * @param float $bundleQty
     * @return array<int, array{0: OrderItem, 1: float}>
     */
    private function shares(OrderItem $bundle, float $bundleQty): array
    {
        $ordered = (float)$bundle->getQtyOrdered();

        if ($ordered <= 0) {
            return [];
        }

        $children = [];

        foreach ($bundle->getChildrenItems() as $child) {
            $children[] = [$child, $bundleQty * (float)$child->getQtyOrdered() / $ordered];
        }

        return $children;
    }

    /**
     * The capture lines with the bundle's lines cut to what Qliro reserved of them and has not captured yet
     *
     * Magento puts the bundle row on every invoice and shipment, and its invoiced quantity cannot tell what
     * Qliro took: an invoice captures only with capture on invoice on, and a shipment is invoiced only once
     * Qliro confirms it. An order reserved before the children went out for every bundle holds fewer of them
     * than it ordered. An order placed without the reservation goes as it is.
     *
     * @param QliroOrderItemInterface[] $lines
     * @param QliroOrderItemInterface[] $bundleLines the lines among them that belong to such a bundle
     * @param Order $order
     * @return QliroOrderItemInterface[]
     */
    public function withinReservation(array $lines, array $bundleLines, Order $order): array
    {
        $payment = $order->getPayment();
        $reservation = $payment
            ? $payment->getAdditionalInformation(Config::QLIROONE_ADDITIONAL_INFO_RESERVED_DISCOUNT)
            : null;
        $reserved = is_array($reservation) ? (array)($reservation['products'] ?? []) : [];

        if (!$bundleLines || !$reserved) {
            return $lines;
        }

        $captured = $payment->getAdditionalInformation(Config::QLIROONE_ADDITIONAL_INFO_CAPTURED_UNITS);
        $captured = is_array($captured) ? $captured : [];

        // The reservation is recorded since 1.8.19, every such order carries the item id in its references
        foreach ($bundleLines as $line) {
            $reference = $line->getMerchantReference();

            if (!isset($reserved[$reference])) {
                continue;
            }

            $left = max(0, (int)$reserved[$reference] - (int)($captured[$reference] ?? 0));
            $line->setQuantity((float)min((int)$line->getQuantity(), $left));
            $captured[$reference] = ($captured[$reference] ?? 0) + (int)$line->getQuantity();
        }

        // The zero bundle line alone would send Qliro a capture of nothing
        $charged = array_filter(
            $bundleLines,
            static fn($line): bool => $line->getQuantity() > 0 && $line->getPricePerItemIncVat() > 0
        );

        return array_values(array_filter(
            $lines,
            static fn($line): bool => !in_array($line, $bundleLines, true)
                || ($line->getQuantity() > 0 && ($charged || $line->getPricePerItemIncVat() > 0))
        ));
    }

    /**
     * Quantity per order item of a document, the children of such a bundle in place of its bundle row
     *
     * Top level rows otherwise, as a child follows its parent. A shipment of such a bundle shipped together
     * saves the bundle row only, then each child counts its share of it.
     *
     * @param array<int, float> $quantities per order item id, as the document holds them
     * @param Order $order
     * @return array<int, float>
     */
    public function chargedQuantities(array $quantities, Order $order): array
    {
        $charged = [];
        $withChildren = [];

        foreach (array_keys($quantities) as $itemId) {
            $item = $order->getItemById($itemId);

            if ($item && $this->isPricedChild($item, $order)) {
                $withChildren[(int)$item->getParentItemId()] = true;
            }
        }

        foreach ($quantities as $itemId => $qty) {
            $item = $order->getItemById($itemId);

            if (!$item) {
                continue;
            }

            if ($this->isPricedByChildren($item)) {
                $shares = isset($withChildren[(int)$itemId]) ? [] : $this->shares($item, $qty);

                foreach ($shares as [$child, $childQty]) {
                    $charged[(int)$child->getId()] = ($charged[(int)$child->getId()] ?? 0.0) + $childQty;
                }

                continue;
            }

            if ($item->getParentItemId() && !$this->isPricedChild($item, $order)) {
                continue;
            }

            $charged[(int)$itemId] = ($charged[(int)$itemId] ?? 0.0) + $qty;
        }

        return $charged;
    }

    /**
     * How much of a child an invoice for a confirmed capture records: what it ships, as far as it is left to invoice
     *
     * @param OrderItem $child
     * @param float $qty
     * @return float
     */
    public function invoiceable(OrderItem $child, float $qty): float
    {
        return max(0.0, min($qty, (float)$child->getQtyToInvoice()));
    }
}
