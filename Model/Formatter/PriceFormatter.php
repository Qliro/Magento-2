<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Formatter;

/**
 * Formats monetary values to the format required by the QliroOne API (two decimal places, dot separator).
 */
class PriceFormatter
{
    /**
     * Format a price value for the QliroOne API.
     *
     * @param float $value
     * @return string e.g. "12.50"
     */
    public function format(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
