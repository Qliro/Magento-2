<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder;

use Magento\Framework\DataObject;
use Magento\Tax\Model\Config as TaxConfig;

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
     * @param TaxConfig $taxConfig
     */
    public function __construct(private readonly TaxConfig $taxConfig)
    {
    }

    /**
     * Resolve the discount amounts, both returned positive and rounded, as [inc VAT, ex VAT]
     *
     * @param DataObject $totals Quote address or order, both carry the same total fields
     * @param int|null $storeId
     * @return float[]
     */
    public function resolve(DataObject $totals, ?int $storeId = null): array
    {
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

        // Prices exclude tax and tax follows the discount, Magento's own default: the discount is an
        // ex VAT amount and the VAT drops with it, so the line has to carry that VAT back or Qliro
        // charges the customer more than Magento's grand total
        return [$this->round($discount + $this->getDiscountVat($totals, $discount)), $discount];
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
     * @return float
     */
    private function getDiscountVat(DataObject $totals, float $discount): float
    {
        $vatOnLines = (float)$totals->getData('subtotal_incl_tax') - (float)$totals->getData('subtotal')
            + (float)$totals->getData('shipping_incl_tax') - (float)$totals->getData('shipping_amount');

        $vat = $this->round($vatOnLines - (float)$totals->getData('tax_amount'));

        // A rate at or above 100 percent is not a tax setup, it is broken totals. Sending no VAT
        // undercharges, grossing up on nonsense overcharges
        if ($vat <= self::EPSILON || $vat >= $discount) {
            return 0.0;
        }

        return $vat;
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
