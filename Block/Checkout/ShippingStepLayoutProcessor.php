<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Block\Checkout;

use Magento\Checkout\Block\Checkout\LayoutProcessorInterface;
use Magento\Framework\App\RequestInterface;
use Qliro\QliroOne\Model\Config;

/**
 * Hides the native Magento shipping step on the QliroOne checkout page
 */
class ShippingStepLayoutProcessor implements LayoutProcessorInterface
{
    /**
     * Full action name of the QliroOne checkout page
     */
    const QLIROONE_CHECKOUT_ACTION = 'checkout_qliro_index';

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    private $request;

    /**
     * @var \Qliro\QliroOne\Model\Config
     */
    private $qliroConfig;

    /**
     * Inject dependencies
     *
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Qliro\QliroOne\Model\Config $qliroConfig
     */
    public function __construct(
        RequestInterface $request,
        Config $qliroConfig
    ) {
        $this->request = $request;
        $this->qliroConfig = $qliroConfig;
    }

    /**
     * Empty the shipping component template, so the step renders nothing above the QliroOne widget
     *
     * The component itself is left in place, because it pulls in the shipping rate service
     * that collects the rates the QliroOne widget offers.
     *
     * @param array $jsLayout
     * @return array
     */
    public function process($jsLayout)
    {
        if ($this->request->getFullActionName() !== self::QLIROONE_CHECKOUT_ACTION) {
            return $jsLayout;
        }

        if (!$this->qliroConfig->isHideNativeShippingStep()) {
            return $jsLayout;
        }

        $steps = $jsLayout['components']['checkout']['children']['steps']['children'] ?? [];

        if (!isset($steps['shipping-step']['children']['shippingAddress'])) {
            return $jsLayout;
        }

        $jsLayout['components']['checkout']['children']['steps']['children']
            ['shipping-step']['children']['shippingAddress']['config']['template'] = '';

        return $jsLayout;
    }
}
