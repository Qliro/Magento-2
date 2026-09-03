<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder;

/**
 * The VAT rate that describes the two price fields of a line sent to Qliro
 */
class LineVatRate
{
    /**
     * Below this a price is nothing at all, and nothing carries no VAT
     */
    public const EPSILON = 0.0001;

    /**
     * Amounts sent to Qliro carry two decimals, and so must the rate that describes them
     */
    private const PRECISION = 2;

    /**
     * Derive the rate from the amounts the line carries, before they are rounded for sending
     *
     * The amounts go out with two decimals, and deriving the rate from those rounded figures would
     * state a rate no jurisdiction charges: 4.79 ex VAT at 25 percent is 5.9875, sent as 5.99, and
     * 5.99 over 4.79 reads back as 25.05. The rate itself is capped at two decimals because the
     * Qliro API refuses more, GitHub issue #122.
     *
     * A line whose ex VAT amount is not above zero, or that carries no VAT at all, gets 0.
     * Anything else would state a VAT the line does not hold.
     *
     * @param float $incVat
     * @param float $exVat
     * @return float
     */
    public function fromPrices(float $incVat, float $exVat): float
    {
        $incVat = abs($incVat);
        $exVat = abs($exVat);

        if ($exVat <= self::EPSILON || $incVat <= $exVat) {
            return 0.0;
        }

        return round(($incVat / $exVat - 1) * 100, self::PRECISION);
    }
}
