<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Plugin;

use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Model\Quote;

class ZeroTotalPlugin
{
    /**
     * @param \Magento\Payment\Model\Checks\ZeroTotal $subject
     * @param bool $result
     * @param MethodInterface $paymentMethod
     * @param Quote $quote
     * @return bool
     */
    public function afterIsApplicable(
        \Magento\Payment\Model\Checks\ZeroTotal $subject,
        bool $result,
        MethodInterface $paymentMethod,
        Quote $quote
    ): bool {
        if ($paymentMethod->getCode() == $quote->getPayment()->getMethod() &&
            $paymentMethod->getCode() == \Qliro\QliroOne\Model\Method\QliroOne::PAYMENT_METHOD_CHECKOUT_CODE) {
            $result = true;
        }

        return $result;
    }
}
