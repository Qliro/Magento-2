<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Order;

use Magento\Sales\Model\Order;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * Class OrderFeeSyncer
 */
readonly class OrderFeeSyncer
{
    /**
     * @param LogManager $logManager
     */
    public function __construct(
        private LogManager $logManager
    ) {
    }

    /**
     * @param Order $order
     * @param array $qliroOrder
     */
    public function sync(Order $order, array $qliroOrder): void
    {
        $fees = [];
        $newFeeInclTax = 0.0;
        foreach ($qliroOrder['OrderItems'] ?? [] as $index => $item) {
            if (($item['Type'] ?? null) !== 'Fee') {
                continue;
            }
            $fees[$index] = $item;
            $newFeeInclTax += (float) ($item['PricePerItemIncVat'] ?? 0);
        }

        $payment = $order->getPayment();
        $oldFees = $payment->getAdditionalInformation('qliroone_fees');
        $oldFeeInclTax = 0.0;
        if (is_array($oldFees)) {
            foreach ($oldFees as $fee) {
                $oldFeeInclTax += (float) ($fee['PricePerItemIncVat'] ?? 0);
            }
        }

        if ($fees) {
            $payment->setAdditionalInformation('qliroone_fees', $fees);
        } elseif (is_array($oldFees)) {
            $payment->unsAdditionalInformation('qliroone_fees');
        }

        $delta = round($newFeeInclTax - $oldFeeInclTax, 4);
        if (abs($delta) < 0.0001) {
            return;
        }

        $order->setGrandTotal(    round((float) $order->getGrandTotal()     + $delta, 4));
        $order->setBaseGrandTotal(round((float) $order->getBaseGrandTotal() + $delta, 4));

        $this->logManager->debug('OrderFeeSyncer: adjusted fee', [
            'extra' => [
                'order_id'    => $order->getId(),
                'fee_before'  => $oldFeeInclTax,
                'fee_after'   => $newFeeInclTax,
                'grand_total' => $order->getGrandTotal(),
            ],
        ]);
    }
}
