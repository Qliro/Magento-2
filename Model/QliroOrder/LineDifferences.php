<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder;

use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;

/**
 * The product lines on which a cart and a Qliro order disagree
 *
 * Lines are matched by merchant reference. Qliro merges lines that share one, so a reference
 * repeated on either side cannot be compared and counts as a difference.
 */
class LineDifferences
{
    public const REASON_REPEATED = 'repeated reference';
    public const REASON_NOT_IN_CART = 'not in the cart';
    public const REASON_NOT_IN_QLIRO = 'not in the Qliro order';
    public const REASON_QUANTITY = 'quantity';
    public const REASON_PRICE = 'price';
    public const REASON_TYPE = 'type';

    private const SKIPPED_TYPES = [QliroOrderItemInterface::TYPE_SHIPPING, QliroOrderItemInterface::TYPE_FEE];

    /**
     * Shipping and fee lines are left out
     *
     * @param QliroOrderItemInterface[] $cartLines
     * @param QliroOrderItemInterface[] $qliroLines
     * @return array<int, array<string, mixed>> Reference, reason, and quantity and prices on each side,
     *     null on a side without the line. Empty when the lines match
     */
    public function between(array $cartLines, array $qliroLines): array
    {
        $cart = $this->index($cartLines);
        $qliro = $this->index($qliroLines);
        $differences = [];

        foreach (array_keys($cart + $qliro) as $reference) {
            $cartLine = $cart[$reference] ?? null;
            $qliroLine = $qliro[$reference] ?? null;
            $reason = $this->reason($cartLine, $qliroLine);

            if ($reason !== null) {
                $differences[] = [
                    'merchant_reference' => (string)$reference,
                    'reason' => $reason,
                    'cart_quantity' => $cartLine['quantity'] ?? null,
                    'qliro_quantity' => $qliroLine['quantity'] ?? null,
                    'cart_price' => $this->prices($cartLine),
                    'qliro_price' => $this->prices($qliroLine),
                ];
            }
        }

        return $differences;
    }

    /**
     * @param QliroOrderItemInterface[] $lines
     * @return array<string, array{item: QliroOrderItemInterface, quantity: float, count: int}>
     */
    private function index(array $lines): array
    {
        $index = [];

        foreach ($lines as $line) {
            if (in_array($line->getType(), self::SKIPPED_TYPES, true)) {
                continue;
            }

            $reference = (string)$line->getMerchantReference();
            $index[$reference] ??= ['item' => $line, 'quantity' => 0.0, 'count' => 0];
            $index[$reference]['quantity'] += (float)$line->getQuantity();
            $index[$reference]['count']++;
        }

        return $index;
    }

    /**
     * @param array{item: QliroOrderItemInterface, quantity: float, count: int}|null $line
     * @return array{inc_vat: mixed, ex_vat: mixed}|null
     */
    private function prices(?array $line): ?array
    {
        return $line === null ? null : [
            'inc_vat' => $line['item']->getPricePerItemIncVat(),
            'ex_vat' => $line['item']->getPricePerItemExVat(),
        ];
    }

    /**
     * @param array{item: QliroOrderItemInterface, quantity: float, count: int}|null $cart
     * @param array{item: QliroOrderItemInterface, quantity: float, count: int}|null $qliro
     * @return string|null
     */
    private function reason(?array $cart, ?array $qliro): ?string
    {
        if ($cart === null) {
            return self::REASON_NOT_IN_CART;
        }

        if ($qliro === null) {
            return self::REASON_NOT_IN_QLIRO;
        }

        if ($cart['count'] > 1 || $qliro['count'] > 1) {
            return self::REASON_REPEATED;
        }

        $cartItem = $cart['item'];
        $qliroItem = $qliro['item'];

        // To the öre, the two sides are computed by different code
        if (round((float)$cartItem->getPricePerItemExVat(), 2) !== round((float)$qliroItem->getPricePerItemExVat(), 2)
            || round((float)$cartItem->getPricePerItemIncVat(), 2) !== round((float)$qliroItem->getPricePerItemIncVat(), 2)
        ) {
            return self::REASON_PRICE;
        }

        if ($cartItem->getQuantity() != $qliroItem->getQuantity()) {
            return self::REASON_QUANTITY;
        }

        if ($cartItem->getType() != $qliroItem->getType()) {
            return self::REASON_TYPE;
        }

        return null;
    }
}
