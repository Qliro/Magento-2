<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder;

use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;

/**
 * The line that closes the öre between what the store charges and what the order lines add up to
 *
 * An order line states one price per unit with two decimals, and Qliro reads the total of a line
 * as that price times the quantity. Magento reads it the other way round: it keeps the unit price
 * unrounded and rounds the row once. A catalogue priced without VAT is where the two part company,
 * 19.75 plus 25 percent is 24.6875 a unit, so a cart of 25 costs 617.19 in the store and 617.25
 * on the line. The checkout compares the two totals, refuses to unlock the iframe while they
 * disagree, and the customer never reaches a payment method.
 *
 * The difference is stated as its own line rather than hidden in a unit price, so every product
 * line keeps the price the cart shows and the total still matches to the öre.
 */
class RoundingAdjustment
{
    /**
     * The reference of the line, its identity to Qliro and the key a capture is matched by
     */
    public const MERCHANT_REFERENCE = 'ROUNDING';

    /**
     * What the line is called in the checkout. Not translated, the way the discount and the
     * refund lines are not: the capture has to state the line the reservation holds, and the two
     * are built in different locales
     */
    public const DESCRIPTION = 'Rounding';

    /**
     * Rounding a unit price moves it by less than half an öre, so the drift a cart can honestly
     * produce is bounded by its own quantity. One öre on top of that is the store's own total,
     * which Magento rounds once more. Anything larger is a disagreement about the amounts
     * themselves, and this class is not the place to settle one: the line stays unabsorbed and
     * the checkout guard fires, which is what it exists for.
     */
    private const MAX_DRIFT_PER_UNIT = 0.5;
    private const MAX_DRIFT_ON_TOP = 1.0;

    /**
     * @var LineVatRate
     */
    private readonly LineVatRate $lineVatRate;

    /**
     * @param QliroOrderItemInterfaceFactory $qliroOrderItemFactory
     * @param LineVatRate|null $lineVatRate
     */
    public function __construct(
        private readonly QliroOrderItemInterfaceFactory $qliroOrderItemFactory,
        ?LineVatRate $lineVatRate = null
    ) {
        $this->lineVatRate = $lineVatRate ?? new LineVatRate();
    }

    /**
     * The adjustment the given lines need to add up to the total the store charges, or null
     *
     * @param QliroOrderItemInterface[] $items
     * @param float $expectedTotalIncVat
     * @return array{incVat: float, exVat: float, vatRate: float, description: string}|null
     */
    public function resolve(array $items, float $expectedTotalIncVat): ?array
    {
        $driftMinor = $this->toMinor($expectedTotalIncVat) - $this->linesTotalMinor($items);

        if ($driftMinor === 0 || abs($driftMinor) > $this->maxDriftMinor($items)) {
            return null;
        }

        $incVat = $driftMinor / 100;

        $vatRate = $this->dominantVatRate($items);

        return [
            'incVat' => $incVat,
            'exVat' => $this->lineVatRate->exVatFromIncVat($incVat, $vatRate),
            'vatRate' => $vatRate,
            'description' => self::DESCRIPTION,
        ];
    }

    /**
     * What the given lines are short of, or over, the total the store charges
     *
     * Zero is a cart that adds up. Anything beyond `maxDifference()` is a disagreement this class
     * refuses to absorb, and the caller is the one that can say so in the log
     *
     * @param QliroOrderItemInterface[] $items
     * @param float $expectedTotalIncVat
     * @return float
     */
    public function difference(array $items, float $expectedTotalIncVat): float
    {
        return ($this->toMinor($expectedTotalIncVat) - $this->linesTotalMinor($items)) / 100;
    }

    /**
     * The largest difference rounding alone can account for in the given lines
     *
     * @param QliroOrderItemInterface[] $items
     * @return float
     */
    public function maxDifference(array $items): float
    {
        return $this->maxDriftMinor($items) / 100;
    }

    /**
     * The order line an adjustment goes out as
     *
     * A line that takes an amount off the order is a discount and one that puts an amount on it is
     * a fee, the two types Qliro has for an amount that is not a product. Every other fee line the
     * module knows comes from Qliro rather than going to it, so the fee branch was put to the
     * sandbox before it was written: order 5569032, one product line and a fee of 0.06, accepted
     * and totalled exactly. The capture has to state the line as the reservation holds it, so both
     * sides build it here.
     *
     * @param array{incVat: float, exVat: float, vatRate: float, description: string} $adjustment
     * @return QliroOrderItemInterface
     */
    public function buildItem(array $adjustment): QliroOrderItemInterface
    {
        $incVat = (float)$adjustment['incVat'];

        /** @var QliroOrderItemInterface $item */
        $item = $this->qliroOrderItemFactory->create();
        $item->setMerchantReference(self::MERCHANT_REFERENCE);
        $item->setDescription((string)($adjustment['description'] ?? self::DESCRIPTION));
        $item->setType(
            $incVat < 0 ? QliroOrderItemInterface::TYPE_DISCOUNT : QliroOrderItemInterface::TYPE_FEE
        );
        $item->setQuantity(1);
        $item->setPricePerItemIncVat($incVat);
        $item->setPricePerItemExVat((float)$adjustment['exVat']);
        $item->setVatRate((float)$adjustment['vatRate']);
        $item->setMetadata(['qliro' => 'checkout']);

        return $item;
    }

    /**
     * The adjustment line among the given ones, or null when they hold none
     *
     * @param QliroOrderItemInterface[] $items
     * @return QliroOrderItemInterface|null
     */
    public function findIn(array $items): ?QliroOrderItemInterface
    {
        $types = [QliroOrderItemInterface::TYPE_DISCOUNT, QliroOrderItemInterface::TYPE_FEE];

        foreach ($items as $item) {
            // The type as well as the reference, because a product may be called ROUNDING: an
            // order reserved before 1.7.42 carries the bare sku as its line reference
            if ($item->getMerchantReference() === self::MERCHANT_REFERENCE
                && in_array($item->getType(), $types, true)
            ) {
                return $item;
            }
        }

        return null;
    }

    /**
     * What the given line holds, in the shape `resolve()` and `buildItem()` speak
     *
     * @param QliroOrderItemInterface $item
     * @return array{incVat: float, exVat: float, vatRate: float, description: string}
     */
    public function toArray(QliroOrderItemInterface $item): array
    {
        return [
            'incVat' => $item->getPricePerItemIncVat(),
            'exVat' => $item->getPricePerItemExVat(),
            'vatRate' => $item->getVatRate(),
            'description' => $item->getDescription(),
        ];
    }

    /**
     * Whether the given value is an adjustment this class can put back on the wire
     *
     * @param mixed $adjustment
     * @return bool
     */
    public function isAdjustment($adjustment): bool
    {
        return is_array($adjustment)
            && isset($adjustment['incVat'], $adjustment['exVat'], $adjustment['vatRate'])
            && is_numeric($adjustment['incVat'])
            && (float)$adjustment['incVat'] !== 0.0;
    }

    /**
     * @param QliroOrderItemInterface[] $items
     * @return int
     */
    private function linesTotalMinor(array $items): int
    {
        $total = 0;

        foreach ($items as $item) {
            $total += $this->toMinor($item->getPricePerItemIncVat() * $item->getQuantity());
        }

        return $total;
    }

    /**
     * @param QliroOrderItemInterface[] $items
     * @return float
     */
    private function maxDriftMinor(array $items): float
    {
        $units = 0.0;

        foreach ($items as $item) {
            if ($item->getType() === QliroOrderItemInterface::TYPE_PRODUCT) {
                $units += abs($item->getQuantity());
            }
        }

        return self::MAX_DRIFT_PER_UNIT * $units + self::MAX_DRIFT_ON_TOP;
    }

    /**
     * The rate of the product line the drift most likely came from, the largest one
     *
     * The drift is made of fractions of an öre from every line at once, so no single rate
     * describes it. The largest line is the one that contributed most of it, and the amount is
     * öre, so the VAT it states is a fraction of an öre either way. The rate is the one the goods
     * carry rather than one derived back from the two amounts, which at this size would read as
     * 20 percent off -0.06 and -0.05, so the öre lands in the VAT bucket it came out of.
     *
     * @param QliroOrderItemInterface[] $items
     * @return float
     */
    private function dominantVatRate(array $items): float
    {
        $rate = null;
        $largest = 0.0;

        foreach ($items as $item) {
            if ($item->getType() !== QliroOrderItemInterface::TYPE_PRODUCT) {
                continue;
            }

            $lineTotal = abs($item->getPricePerItemIncVat() * $item->getQuantity());

            // A cart of free lines, a dynamically priced bundle parent among them, has no largest
            // line to read the rate off. The first one still states the rate the cart is taxed at,
            // which beats declaring the öre VAT free
            if ($rate === null || $lineTotal > $largest) {
                $largest = max($largest, $lineTotal);
                $rate = $item->getVatRate();
            }
        }

        return $rate ?? 0.0;
    }

    /**
     * @param float $amount
     * @return int
     */
    private function toMinor(float $amount): int
    {
        return (int)round($amount * 100);
    }
}
