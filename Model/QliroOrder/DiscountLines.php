<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder;

use Magento\Framework\DataObject;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Helper\Data as QliroHelper;

/**
 * The discount lines of a cart, one per product line, so that a capture can take the discount of
 * the items it captures
 *
 * Qliro matches a capture line to a reserved line by reference and price per item, and its
 * quantities are whole, so the discount of a product line goes out per unit. The öre a row discount
 * does not divide into goes on a remainder line of its own, captured with the last unit.
 */
class DiscountLines
{
    public const PREFIX = 'DSC_';
    public const REMAINDER_SUFFIX = '_R';
    public const SHIPPING_REFERENCE = 'DSC_SHIPPING';
    public const ORDER_REFERENCE = 'DSC_ORDER';

    public const SCOPE_LINE = 'line';
    public const SCOPE_ORDER = 'order';

    /**
     * @param QliroOrderItemInterfaceFactory $qliroOrderItemFactory
     * @param QliroHelper $qliroHelper
     * @param DiscountAmountResolver $discountAmountResolver
     * @param LineVatRate $lineVatRate
     */
    public function __construct(
        private readonly QliroOrderItemInterfaceFactory $qliroOrderItemFactory,
        private readonly QliroHelper $qliroHelper,
        private readonly DiscountAmountResolver $discountAmountResolver,
        private readonly LineVatRate $lineVatRate
    ) {
    }

    /**
     * The reference of the per unit discount line of a product line
     *
     * @param string $productReference
     * @return string
     */
    public function lineReference(string $productReference): string
    {
        return self::PREFIX . $productReference;
    }

    /**
     * The reference of the line that holds the öre the per unit discount leaves over
     *
     * @param string $productReference
     * @return string
     */
    public function remainderReference(string $productReference): string
    {
        return self::PREFIX . $productReference . self::REMAINDER_SUFFIX;
    }

    /**
     * The cart or order item that carries the discount of a product line, or null when another does
     *
     * A configurable's discount is on its parent, which has no line of its own. A bundle priced by
     * its children carries their discounts summed up, and its own line is priced at nothing.
     *
     * @param \Magento\Quote\Model\Quote\Item\AbstractItem|\Magento\Sales\Model\Order\Item $item
     * @return DataObject|null
     */
    public function discountItemOf($item): ?DataObject
    {
        $parent = $item->getParentItem();

        // Magento answers for a child by its parent, so only the parent itself is skipped
        if (!$parent && $item->isChildrenCalculated()) {
            return null;
        }


        if ($parent && $parent->getProductType() === 'configurable') {
            return $parent;
        }

        return $item;
    }

    /**
     * The discount lines of one product line: the per unit one, and the remainder when there is one
     *
     * @param string $productReference
     * @param int $quantity
     * @param DataObject $discountItem
     * @param int|null $storeId
     * @return QliroOrderItemInterface[]
     */
    public function forProductLine(
        string $productReference,
        int $quantity,
        DataObject $discountItem,
        ?int $storeId
    ): array {
        if ($quantity < 1) {
            return [];
        }

        [$incVat, $exVat] = $this->discountAmountResolver->resolve(
            $this->rowTotals($discountItem),
            [(float)$discountItem->getData('tax_percent')],
            $storeId
        );

        if ($incVat <= DiscountAmountResolver::EPSILON) {
            return [];
        }

        $vatRate = $this->discountAmountResolver->getVatRate($incVat, $exVat);
        $cents = (int)round($incVat * 100);
        $unitCents = intdiv($cents, $quantity);
        $remainderCents = $cents - $unitCents * $quantity;
        $lines = [];

        if ($unitCents > 0) {
            $lines[] = $this->perUnitLine($this->lineReference($productReference), $quantity, $unitCents / 100, $vatRate);
        }

        if ($remainderCents > 0) {
            $lines[] = $this->perUnitLine($this->remainderReference($productReference), 1, $remainderCents / 100, $vatRate);
        }

        return $lines;
    }

    /**
     * The discount a rule put on the shipping, as one line, or null when it put none
     *
     * @param DataObject $totals Quote address or order
     * @param int|null $storeId
     * @return QliroOrderItemInterface|null
     */
    public function forShipping(DataObject $totals, ?int $storeId): ?QliroOrderItemInterface
    {
        $shippingTotals = new DataObject([
            'discount_amount' => $totals->getData('shipping_discount_amount'),
            'discount_tax_compensation_amount' => $totals->getData('shipping_discount_tax_compensation_amount'),
            'subtotal_incl_tax' => 0,
            'subtotal' => 0,
            'shipping_incl_tax' => $totals->getData('shipping_incl_tax'),
            'shipping_amount' => $totals->getData('shipping_amount'),
            'tax_amount' => $totals->getData('shipping_tax_amount'),
        ]);

        if (abs((float)$shippingTotals->getData('discount_amount')) <= DiscountAmountResolver::EPSILON) {
            return null;
        }

        [$incVat, $exVat] = $this->discountAmountResolver->resolve($shippingTotals, [], $storeId);

        return $this->orderLevelLine(self::SHIPPING_REFERENCE, $incVat, $exVat);
    }

    /**
     * One discount line of quantity 1 for the order as a whole
     *
     * @param string $reference
     * @param float $incVat positive
     * @param float $exVat positive
     * @return QliroOrderItemInterface
     */
    public function orderLevelLine(string $reference, float $incVat, float $exVat): QliroOrderItemInterface
    {
        return $this->line(
            $reference,
            1,
            -abs((float)$this->qliroHelper->formatPrice($incVat)),
            -abs((float)$this->qliroHelper->formatPrice($exVat)),
            $this->discountAmountResolver->getVatRate($incVat, $exVat)
        );
    }

    /**
     * A line as Qliro reserved it, with the quantity this capture takes of it
     *
     * @param string $reference
     * @param array $reserved as recorded by {@see self::reservation()}
     * @param int $quantity
     * @return QliroOrderItemInterface
     */
    public function fromReservation(string $reference, array $reserved, int $quantity): QliroOrderItemInterface
    {
        return $this->line(
            $reference,
            $quantity,
            (float)$reserved['inc'],
            (float)$reserved['ex'],
            (float)$reserved['vat']
        );
    }

    /**
     * What a capture needs to know of the reservation: each discount line, and the quantity of each
     * product line, to tell when the last unit of a line is captured
     *
     * @param QliroOrderItemInterface[] $qliroOrderItems the lines of the Qliro order
     * @return array{products: array<string, int>, discounts: array<string, array>}
     */
    public function reservation(array $qliroOrderItems): array
    {
        $products = [];
        $discounts = [];

        foreach ($qliroOrderItems as $line) {
            if ($line->getType() === QliroOrderItemInterface::TYPE_PRODUCT) {
                $reference = $line->getMerchantReference();
                $products[$reference] = ($products[$reference] ?? 0) + (int)$line->getQuantity();
            }
        }

        foreach ($qliroOrderItems as $line) {
            if ($line->getType() !== QliroOrderItemInterface::TYPE_DISCOUNT) {
                continue;
            }

            $reference = $line->getMerchantReference();
            $discounts[$reference] = [
                'inc' => $line->getPricePerItemIncVat(),
                'ex' => $line->getPricePerItemExVat(),
                'vat' => $line->getVatRate(),
                'quantity' => (int)$line->getQuantity(),
                'scope' => $this->productOf($reference, $products) === null ? self::SCOPE_ORDER : self::SCOPE_LINE,
            ];
        }

        return ['products' => $products, 'discounts' => $discounts];
    }

    /**
     * The reservation to record for a cart, or null when the lines read hold none of its discount
     *
     * Recording an empty one would make the capture send no discount at all. Left unrecorded, the
     * order is captured the way an order placed before this was, with one order level line.
     *
     * @param QliroOrderItemInterface[] $qliroOrderItems
     * @param \Magento\Quote\Model\Quote $quote
     * @return array|null
     */
    public function reservationFor(array $qliroOrderItems, $quote): ?array
    {
        $reservation = $this->reservation($qliroOrderItems);
        $address = $quote->isVirtual() ? $quote->getBillingAddress() : $quote->getShippingAddress();

        if (!$reservation['discounts'] && abs((float)$address->getDiscountAmount()) > DiscountAmountResolver::EPSILON) {
            return null;
        }

        return $reservation;
    }

    /**
     * The product line a per line discount reference belongs to, or null for an order level one
     *
     * @param string $reference
     * @param array<string, int> $products
     * @return string|null
     */
    private function productOf(string $reference, array $products): ?string
    {
        if (!str_starts_with($reference, self::PREFIX)) {
            return null;
        }

        $product = substr($reference, strlen(self::PREFIX));

        if (isset($products[$product])) {
            return $product;
        }

        if (str_ends_with($product, self::REMAINDER_SUFFIX)) {
            $product = substr($product, 0, -strlen(self::REMAINDER_SUFFIX));

            return isset($products[$product]) ? $product : null;
        }

        return null;
    }

    /**
     * @param string $reference
     * @param int $quantity
     * @param float $incVat positive, per unit
     * @param float $vatRate
     * @return QliroOrderItemInterface
     */
    private function perUnitLine(string $reference, int $quantity, float $incVat, float $vatRate): QliroOrderItemInterface
    {
        return $this->line(
            $reference,
            $quantity,
            -$incVat,
            -abs($this->lineVatRate->exVatFromIncVat($incVat, $vatRate)),
            $vatRate
        );
    }

    /**
     * @param string $reference
     * @param int $quantity
     * @param float $incVat
     * @param float $exVat
     * @param float $vatRate
     * @return QliroOrderItemInterface
     */
    private function line(
        string $reference,
        int $quantity,
        float $incVat,
        float $exVat,
        float $vatRate
    ): QliroOrderItemInterface {
        /** @var QliroOrderItemInterface $line */
        $line = $this->qliroOrderItemFactory->create();

        $line->setMerchantReference($reference);
        $line->setDescription($reference);
        $line->setType(QliroOrderItemInterface::TYPE_DISCOUNT);
        $line->setQuantity($quantity);
        $line->setPricePerItemIncVat($incVat);
        $line->setPricePerItemExVat($exVat);
        $line->setVatRate($vatRate);
        $line->setMetadata(['qliro' => 'checkout']);

        return $line;
    }

    /**
     * The totals of one row in the shape the resolver reads off an address or an order
     *
     * @param DataObject $item
     * @return DataObject
     */
    private function rowTotals(DataObject $item): DataObject
    {
        return new DataObject([
            'discount_amount' => $item->getData('discount_amount'),
            'discount_tax_compensation_amount' => $item->getData('discount_tax_compensation_amount'),
            'subtotal_incl_tax' => $item->getData('row_total_incl_tax'),
            'subtotal' => $item->getData('row_total'),
            'shipping_incl_tax' => 0,
            'shipping_amount' => 0,
            'tax_amount' => $item->getData('tax_amount'),
        ]);
    }
}
