<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Config\Source;

use Magento\Framework\Option\ArrayInterface;

/**
 * Controls how the QliroOne payment method renders once selected in native checkout,
 * when "Show as payment method" is enabled.
 */
class PaymentMethodRenderMode implements ArrayInterface
{
    /**
     * Redirect to the standalone Qliro checkout page (legacy behaviour).
     */
    public const string MODE_REDIRECT = 'redirect';

    /**
     * Mount the Qliro iframe inline in the payment-method content panel.
     */
    public const string MODE_IFRAME = 'iframe';

    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::MODE_REDIRECT, 'label' => __('Redirect to Qliro checkout page')],
            ['value' => self::MODE_IFRAME, 'label' => __('Embedded iframe in checkout')],
        ];
    }
}
