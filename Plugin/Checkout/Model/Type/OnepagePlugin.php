<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Plugin\Checkout\Model\Type;

use Magento\Checkout\Model\Type\Onepage as Subject;
use Magento\Framework\App\RequestInterface as Request;
use Qliro\QliroOne\Model\Config as QliroConfig;

/**
 * Class OnepagePlugin
 */
class OnepagePlugin
{
    /**
     * @param QliroConfig $config
     * @param Request $request
     */
    public function __construct(
        protected QliroConfig $config,
        protected Request     $request
    ){
    }

    /**
     * Prevent doubled checkout initialization with enabled
     * qliro as a payment method as it leads to preselected data reset
     *
     * @param Subject $subject The subject instance being intercepted. \Magento\Checkout\Model\Type\Onepage
     * @param \Closure $proceed The closure representing the original method being called.
     * @return Subject
     */
    public function aroundInitCheckout(Subject $subject, \Closure $proceed) : Subject
    {
        if ($this->config->isActive()
            && $this->request->getFullActionName() === 'checkout_qliro_index'
            && $this->config->getShowAsPaymentMethod()
        ) {
            return $subject;
        }

        return $proceed($this);
    }
}
