<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder;

use Magento\Framework\Exception\LocalizedException;

/**
 * The quantity of an order line, and the whole number Qliro carries it as
 *
 * Qliro types the quantity of an order line as an integer, on the way out and on the way back, so
 * half a metre of cable has no shape on the wire. Truncating it is the one thing that must not
 * happen: `(int)0.5` is 0, which drops the line out of the capture while Magento records it as
 * invoiced, and `(int)2.5` is 2, which settles less than the order holds. Every place that turns
 * a Magento quantity into a wire quantity asks here, and a fraction is refused naming the line.
 */
class LineQuantity
{
    /**
     * Below this a quantity is the whole number it sits next to
     *
     * A quantity is a decimal column in Magento and arrives through a float, so a cart of three
     * can reach here as 2.9999999999999996. That is three, and refusing it would be a checkout
     * that cannot be finished. The window has to stay far below a quantity a store can really
     * hold: `qty` is `decimal(12,4)`, so the smallest fraction anyone can sell is 0.0001, and
     * 0.9999 of a kilo is a fraction rather than a kilo. Float noise is orders below both.
     */
    public const EPSILON = 0.000001;

    /**
     * Whether the quantity is one Qliro can carry
     *
     * @param float $quantity
     * @return bool
     */
    public function isWhole(float $quantity): bool
    {
        return abs($quantity - round($quantity)) <= self::EPSILON;
    }

    /**
     * The quantity as Qliro carries it
     *
     * Rounded rather than cast, because a quantity that is whole to the epsilon above is only
     * approximately whole as a float, and casting 2.9999999999999996 gives two.
     *
     * @param float $quantity
     * @return int
     */
    public function toWire(float $quantity): int
    {
        return (int)round($quantity);
    }

    /**
     * The quantity a line is sent to the checkout with
     *
     * A whole quantity goes out as the whole number it is. Magento computes the quantity of a
     * child line, a bundle selection times the bundle's own quantity, so three can reach the
     * payload as 2.9999999999999996, and Qliro reads that as a fraction and refuses the order.
     * A quantity that is not whole is sent as it stands: the cart it belongs to is refused
     * before this, and rounding it here would charge a quantity nobody asked for.
     *
     * @param float $quantity
     * @return float
     */
    public function forPayload(float $quantity): float
    {
        return $this->isWhole($quantity) ? (float)$this->toWire($quantity) : $quantity;
    }

    /**
     * The quantity a line settles at, or a refusal naming it
     *
     * The capture, the shipment and the refund all pass through here. An order placed before the
     * checkout refused fractions, or placed through the admin or the API, can still hold one, and
     * settling such a line at a quantity of its own invention is worse than not settling it.
     *
     * @param float $quantity
     * @param string $reference What the line is called to the merchant, its sku
     * @return int
     * @throws LocalizedException
     */
    public function settlementQuantity(float $quantity, string $reference): int
    {
        if (!$this->isWhole($quantity)) {
            throw new LocalizedException(
                __(
                    'Qliro cannot settle a part of an item. The line %1 is %2, and Qliro carries a '
                    . 'whole quantity only, so this order has to be settled outside Magento.',
                    $reference,
                    $quantity
                )
            );
        }

        return $this->toWire($quantity);
    }
}
