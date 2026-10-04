<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder;

use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Model\Config;

/**
 * Records on the order what rounding line its reservation holds
 *
 * Qliro refuses a capture whose lines disagree with the reservation, and refuses it terminally.
 * The adjustment belongs to the cart as a whole rather than to any one item, so the capture
 * cannot derive it from the items it is invoicing, and an order placed before the line existed
 * must not be sent one at all. Both are answered by writing the reserved line down when the order
 * is placed, the way the discount and the reference format are.
 */
class RoundingAdjustmentStamp
{
    /**
     * @param RoundingAdjustment $roundingAdjustment
     */
    public function __construct(private readonly RoundingAdjustment $roundingAdjustment)
    {
    }

    /**
     * Write the adjustment the given reservation holds on the cart's payment, from where Magento
     * carries it to the order. The lines come from Qliro rather than from a fresh build of the
     * cart, so what the capture replays is what was reserved and not what a later reading of the
     * cart would have produced
     *
     * A reservation without such a line clears the key rather than leaving it alone. A placement
     * that failed after this ran leaves the cart active with whatever it wrote, and the customer
     * who then changes the cart until it needs no adjustment is reserved anew without one: a
     * stamp left over from the earlier attempt would put a line on the capture that the
     * reservation does not hold, which is the terminal refusal this class exists to avoid
     *
     * @param Quote $quote
     * @param QliroOrderItemInterface[] $reservationItems
     * @return void
     */
    public function record(Quote $quote, array $reservationItems): void
    {
        $payment = $quote->getPayment();

        if ($payment === null) {
            return;
        }

        $item = $this->roundingAdjustment->findIn($reservationItems);

        if ($item === null) {
            $payment->unsAdditionalInformation(Config::QLIROONE_ADDITIONAL_INFO_ROUNDING_ADJUSTMENT);

            return;
        }

        $payment->setAdditionalInformation(
            Config::QLIROONE_ADDITIONAL_INFO_ROUNDING_ADJUSTMENT,
            $this->roundingAdjustment->toArray($item)
        );
    }
}
