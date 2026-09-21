<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Builder\Handler;

use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\Builder\OrderItemHandlerInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\LinesTotal;
use Qliro\QliroOne\Model\QliroOrder\RoundingAdjustment;

/**
 * Puts the öre between the store's total and the sum of the lines on a line of its own
 *
 * Runs last, so the discount is already on the order: the adjustment closes what is left after
 * every other line has stated its amount.
 */
final class RoundingAdjustmentHandler implements OrderItemHandlerInterface
{
    /**
     * @param RoundingAdjustment $roundingAdjustment
     * @param LinesTotal $linesTotal
     * @param LogManager $logManager
     */
    public function __construct(
        private readonly RoundingAdjustment $roundingAdjustment,
        private readonly LinesTotal         $linesTotal,
        private readonly LogManager         $logManager
    ) {
    }

    /**
     * @inheritDoc
     */
    public function handle($orderItems, $quote): array
    {
        if (!$quote instanceof Quote || empty($orderItems)) {
            return $orderItems;
        }

        $expectedTotal = $this->linesTotal->ofQuote($quote);
        $adjustment = $this->roundingAdjustment->resolve($orderItems, $expectedTotal);

        if ($adjustment === null) {
            $this->logRefusedDifference($orderItems, $quote, $expectedTotal);

            return $orderItems;
        }

        $this->logManager->debug(
            'Order lines do not add up to the cart total, sending the difference as its own line',
            [
                'extra' => [
                    'quote_id' => $quote->getId(),
                    'amount_inc_vat' => $adjustment['incVat'],
                ],
            ]
        );

        $orderItems[] = $this->roundingAdjustment->buildItem($adjustment);

        return $orderItems;
    }

    /**
     * Say in the log when the lines and the cart disagree by more than rounding can explain
     *
     * That cart is the one that reaches support: the checkout keeps the iframe locked over the
     * difference and nothing else says what it was.
     *
     * @param QliroOrderItemInterface[] $orderItems
     * @param Quote $quote
     * @param float $expectedTotal
     * @return void
     */
    private function logRefusedDifference(array $orderItems, Quote $quote, float $expectedTotal): void
    {
        $difference = $this->roundingAdjustment->difference($orderItems, $expectedTotal);

        if (abs($difference) <= 0.0) {
            return;
        }

        $this->logManager->debug(
            'Order lines disagree with the cart total by more than rounding can explain, leaving it',
            [
                'extra' => [
                    'quote_id' => $quote->getId(),
                    'difference_inc_vat' => $difference,
                    'largest_rounding_can_explain' => $this->roundingAdjustment->maxDifference($orderItems),
                ],
            ]
        );
    }
}
