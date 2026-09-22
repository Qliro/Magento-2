<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/**
 * How the QliroOne payment method renders once it is selected in the native checkout.
 *
 * Only meaningful while "Show as payment method" is enabled.
 */
class PaymentMethodRenderMode implements OptionSourceInterface
{
    /**
     * Send the buyer to the standalone Qliro checkout page. The behaviour before 1.7.44.
     */
    public const MODE_REDIRECT = 'redirect';

    /**
     * Mount the Qliro checkout in the payment method panel, without leaving the native checkout.
     */
    public const MODE_IFRAME = 'iframe';

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
