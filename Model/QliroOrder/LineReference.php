<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder;

use Magento\Sales\Model\Order;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Model\Config;

/**
 * The merchant reference of a product line, and the two formats it has had
 *
 * Qliro identifies a line by its merchant reference: it merges two lines that carry the same one
 * and sums their quantity, and it matches a capture against the reservation by it. The sku alone
 * is therefore not enough, a cart may hold one sku on several lines, so the quote item id goes in
 * front of it. Every order line the module sends is built through here.
 */
class LineReference
{
    /**
     * The separator, in front of the sku since before 1.7.0. A sku may hold one of its own, so
     * the id is everything before the first one and the sku is the rest
     */
    private const SEPARATOR = ':';

    /**
     * The reference of a line standing for the given cart or order item
     *
     * @param string|int|null $itemId
     * @param string $sku
     * @return string
     */
    public function forItem(string|int|null $itemId, string $sku): string
    {
        if ($itemId === null || $itemId === '') {
            return $sku;
        }

        return $itemId . self::SEPARATOR . $sku;
    }

    /**
     * The sku part of a reference, whichever format it is in
     *
     * @param string $reference
     * @return string
     */
    public function skuOf(string $reference): string
    {
        $parts = $this->split($reference);

        return $parts === null ? $reference : $parts[1];
    }

    /**
     * The item id part of a reference, or null when the reference carries none
     *
     * @param string $reference
     * @return string|null
     */
    public function itemIdOf(string $reference): ?string
    {
        $parts = $this->split($reference);

        return $parts === null ? null : $parts[0];
    }

    /**
     * Split a reference into its item id and its sku, or null when it carries no id
     *
     * A sku may hold a separator of its own, and a reference from before 1.7.40 is a bare sku, so
     * a leading part is only read as an id when it is one: a cart item id is digits and nothing
     * else. `AB:12` is therefore a sku whole, and `519:AB:12` is that sku on cart line 519.
     *
     * @param string $reference
     * @return string[]|null
     */
    private function split(string $reference): ?array
    {
        $parts = explode(self::SEPARATOR, $reference, 2);

        if (count($parts) !== 2 || $parts[0] === '' || !ctype_digit($parts[0])) {
            return null;
        }

        return $parts;
    }

    /**
     * Whether the reservation of the given order was built with the item id in its references
     *
     * Stamped on the payment when the module places the order. An order placed before 1.7.40 has
     * no stamp and was reserved with the bare sku, and Qliro refuses a capture whose lines
     * disagree with the reservation, so its lines have to go out the way they went out then.
     *
     * @param Order $order
     * @return bool
     */
    public function reservationCarriesItemId(Order $order): bool
    {
        $payment = $order->getPayment();

        if ($payment === null) {
            return false;
        }

        return (bool)$payment->getAdditionalInformation(
            Config::QLIROONE_ADDITIONAL_INFO_LINE_REFERENCE_CARRIES_ITEM_ID
        );
    }

    /**
     * Put the product lines of an order management request back into the format its reservation
     * holds, for an order the module placed before the item id was part of a reference
     *
     * @param QliroOrderItemInterface[] $items
     * @param Order $order
     * @return QliroOrderItemInterface[]
     */
    public function alignWithReservation(array $items, Order $order): array
    {
        if ($this->reservationCarriesItemId($order)) {
            return $items;
        }

        foreach ($items as $item) {
            if ($item->getType() !== QliroOrderItemInterface::TYPE_PRODUCT) {
                continue;
            }

            $item->setMerchantReference($this->skuOf($item->getMerchantReference()));
        }

        return $items;
    }
}
