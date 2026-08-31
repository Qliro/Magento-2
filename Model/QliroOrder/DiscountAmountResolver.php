<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder;

use Magento\Framework\DataObject;
use Magento\Tax\Model\Config as TaxConfig;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * Owns the three price fields of the discount line sent to Qliro, for the checkout and for order
 * management alike.
 *
 * Which side of the discount Magento hands us, and whether the VAT moves with it at all, is decided
 * by the tax configuration, see \Magento\Tax\Model\Calculation\UnitBaseCalculator.
 */
class DiscountAmountResolver
{
    /**
     * Below this a discount amount is nothing at all, shared with the handlers that call this
     */
    public const EPSILON = 0.0001;

    /**
     * Amounts sent to Qliro carry two decimals, and so must the rate that describes them
     */
    private const PRECISION = 2;

    /**
     * One öre of slack on the VAT ceiling, every total it is derived from is rounded on its own
     */
    private const TOLERANCE = 0.01;

    /**
     * @param TaxConfig $taxConfig
     * @param LogManager $logManager
     */
    public function __construct(
        private readonly TaxConfig  $taxConfig,
        private readonly LogManager $logManager
    ) {
    }

    /**
     * Resolve the discount amounts, both returned positive and rounded, as [inc VAT, ex VAT]
     *
     * @param DataObject $totals Quote address or order, both carry the same total fields
     * @param float[] $lineVatRates The VAT rates of the lines the discount is spread over
     * @param int|null $storeId
     * @param bool $mayCarryDiscountVat False reproduces the line as it was sent before 1.7.18, see
     *                                  the branch below. The checkout builds the reservation, so it
     *                                  always says true; a capture has to match the reservation it
     *                                  was given
     * @return float[]
     */
    public function resolve(
        DataObject $totals,
        array $lineVatRates,
        ?int $storeId = null,
        bool $mayCarryDiscountVat = true
    ): array {
        $discount = $this->round(abs((float)$totals->getData('discount_amount')));
        $compensation = abs((float)$totals->getData('discount_tax_compensation_amount'));

        // Prices include tax and tax follows the discount, the Nordic setup: the discount is an inc
        // VAT amount and Magento states its VAT part outright
        if ($compensation > self::EPSILON && $compensation < $discount) {
            return [$discount, $this->round($discount - $compensation)];
        }

        // Prices include tax without the compensation, or tax calculated before the discount: the
        // VAT does not move with the discount, so the line carries none
        if ($this->taxConfig->priceIncludesTax($storeId) || !$this->taxConfig->applyTaxAfterDiscount($storeId)) {
            return [$discount, $discount];
        }

        // A capture whose reservation was made before 1.7.18 has to reproduce the line that was
        // reserved, VAT free. Qliro refuses a capture whose lines disagree with the reservation
        // (INVALID_ITEM), so grossing up here would leave the order uncapturable rather than merely
        // over-charged, which is what the reservation already is
        if (!$mayCarryDiscountVat) {
            return [$discount, $discount];
        }

        // Prices exclude tax and tax follows the discount, Magento's own default: the discount is an
        // ex VAT amount and the VAT drops with it, so the line has to carry that VAT back or Qliro
        // charges the customer more than Magento's grand total
        return [$this->round($discount + $this->getDiscountVat($totals, $discount, $lineVatRates)), $discount];
    }

    /**
     * Derive the VAT rate of the discount line from the amounts that are actually sent
     *
     * @param float $discountInclVat
     * @param float $discountExclVat
     * @return float
     */
    public function getVatRate(float $discountInclVat, float $discountExclVat): float
    {
        if ($discountExclVat <= self::EPSILON || $discountInclVat <= $discountExclVat) {
            return 0.0;
        }

        return $this->round(($discountInclVat / $discountExclVat - 1) * 100);
    }

    /**
     * The VAT the discount took away: what the lines we send still carry, less what Magento charges
     *
     * Magento states no compensation in this configuration, but it does leave both sides of the
     * calculation on the totals. The inc VAT subtotal and shipping are taxed before the discount,
     * the tax amount is taxed after it, so the difference is the VAT of the discount, exact for a
     * mixed rate cart and for a rule that discounts shipping too.
     *
     * @param DataObject $totals
     * @param float $discount
     * @param float[] $lineVatRates
     * @return float
     */
    private function getDiscountVat(DataObject $totals, float $discount, array $lineVatRates): float
    {
        $vatOnLines = (float)$totals->getData('subtotal_incl_tax') - (float)$totals->getData('subtotal')
            + (float)$totals->getData('shipping_incl_tax') - (float)$totals->getData('shipping_amount');

        $vat = $this->round($vatOnLines - (float)$totals->getData('tax_amount'));

        if ($vat <= self::EPSILON) {
            return 0.0;
        }

        $ceiling = $this->getVatCeiling($totals, $discount, $lineVatRates);

        // Another total collector taxing something these totals do not account for is the way this
        // difference stops describing the discount. Sending it anyway would charge the customer
        // more than Magento asked, so the line goes out without VAT and the store is diagnosable
        if ($vat > $ceiling) {
            $this->logManager->warning(
                'Discount VAT discarded, it is more than the cart\'s own VAT rates allow',
                [
                    'extra' => [
                        'discount' => $discount,
                        'derived_vat' => $vat,
                        'ceiling' => $ceiling,
                        'line_vat_rates' => $lineVatRates,
                    ],
                ]
            );

            return 0.0;
        }

        return $vat;
    }

    /**
     * The most VAT this discount could have taken away
     *
     * A discount is spread over the lines, so its own rate cannot beat the highest rate among them.
     * The rates the lines carry come from the caller, and the two the totals imply are added to
     * them, so a store where Magento left no tax percent on the items still gets a ceiling rather
     * than losing the VAT altogether.
     *
     * @param DataObject $totals
     * @param float $discount
     * @param float[] $lineVatRates
     * @return float
     */
    private function getVatCeiling(DataObject $totals, float $discount, array $lineVatRates): float
    {
        $rates = array_map(static fn($rate): float => abs((float)$rate), $lineVatRates);

        $rates[] = $this->getVatRate(
            (float)$totals->getData('subtotal_incl_tax'),
            (float)$totals->getData('subtotal')
        );
        $rates[] = $this->getVatRate(
            (float)$totals->getData('shipping_incl_tax'),
            (float)$totals->getData('shipping_amount')
        );

        return $this->round($discount * max($rates) / 100) + self::TOLERANCE;
    }

    /**
     * @param float $value
     * @return float
     */
    private function round(float $value): float
    {
        return round($value, self::PRECISION);
    }
}
