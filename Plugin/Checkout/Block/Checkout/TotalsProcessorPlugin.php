<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Plugin\Checkout\Block\Checkout;

use Magento\Checkout\Block\Checkout\TotalsProcessor;

/**
 * Guard against missing sidebar totals structure on non-standard checkout pages
 * (e.g. Qliro One checkout) where the sidebar may not contain the full
 * summary > totals > children path that TotalsProcessor unconditionally reads.
 */
class TotalsProcessorPlugin
{
    public function aroundProcess(TotalsProcessor $subject, callable $proceed, array $jsLayout): array
    {
        if (isset($jsLayout['components']['checkout']['children']['sidebar']['children']['summary']['children']['totals'])) {
            return $proceed($jsLayout);
        } else {
            return $jsLayout;
        }
    }
}
