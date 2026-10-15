<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler;

use Magento\Sales\Model\Order;
use Qliro\QliroOne\Api\Admin\Builder\OrderItemHandlerInterface;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\QliroOrder\RoundingAdjustment;

/**
 * Puts the rounding line of the reservation on the first capture
 *
 * The amount belongs to the cart as a whole, like the discount, so it goes out once and whole
 * rather than being spread over the invoices. An order placed before the line existed carries no
 * stamp and is captured exactly as it was reserved, without one.
 */
final class RoundingAdjustmentHandler implements OrderItemHandlerInterface
{
    /**
     * @param RoundingAdjustment $roundingAdjustment
     */
    public function __construct(private readonly RoundingAdjustment $roundingAdjustment)
    {
    }

    /**
     * @inheritDoc
     */
    public function handle($orderItems, $order): array
    {
        if (!$order instanceof Order || !$order->getFirstCaptureFlag()) {
            return $orderItems;
        }

        $payment = $order->getPayment();

        if ($payment === null) {
            return $orderItems;
        }

        $adjustment = $payment->getAdditionalInformation(Config::QLIROONE_ADDITIONAL_INFO_ROUNDING_ADJUSTMENT);

        if (!$this->roundingAdjustment->isAdjustment($adjustment)) {
            return $orderItems;
        }

        $orderItems[] = $this->roundingAdjustment->buildItem($adjustment);

        return $orderItems;
    }
}
