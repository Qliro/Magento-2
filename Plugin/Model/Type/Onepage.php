<?php
declare(strict_types=1);

namespace Qliro\QliroOne\Plugin\Model\Type;

use \Magento\Checkout\Model\Type\Onepage as Subject;
use \Qliro\QliroOne\Model\Config;
use \Magento\Framework\App\RequestInterface;

class Onepage
{
    /**
     * Class constructor
     *
     * @param Config $config
     * @param RequestInterface $request
     */
    public function __construct(
        private readonly Config $config,
        private readonly RequestInterface $request
    ) {
    }

    /**
     * Prevent doubled checkout initialization with enabled
     * qliro as a payment method as it leads to preselected data reset
     *
     * @param Subject $subject The subject instance being intercepted.
     * @param \Closure $proceed The closure representing the original method being called.
     * @return Subject
     */
    public function aroundInitCheckout(Subject $subject, \Closure $proceed): Subject
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
