<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

// @codingStandardsIgnoreFile
// phpcs:ignoreFile

namespace Qliro\QliroOne\Console\Callback;

use Symfony\Component\Console\Input\InputInterface;

/**
 * Simulate a Qliro CheckoutStatus push callback locally.
 *
 * Qliro sends this to MerchantCheckoutStatusPushUrl when the checkout
 * session changes state (e.g. customer completes payment → Status=Completed).
 *
 * The handler (Management\CheckoutStatus::update) reads:
 *   OrderId, Status
 *
 * Usage:
 *   bin/magento qliroone:callback:checkout-status <qliro_order_id>
 *   bin/magento qliroone:callback:checkout-status <qliro_order_id> --dry-run
 */
class CheckoutStatusCommand extends AbstractCallbackCommand
{
    protected function getCommandName(): string
    {
        return 'qliroone:callback:checkout-status';
    }

    protected function getCommandDescription(): string
    {
        return '[Dev] Simulate a Qliro CheckoutStatus push to the local store';
    }

    protected function getCallbackPath(): string
    {
        return 'checkout/qliro_callback/checkoutStatus';
    }

    /**
     * Payload mirrors what Qliro sends to MerchantCheckoutStatusPushUrl.
     *
     * The management class reads: OrderId, Status.
     * MerchantReference is included for log context.
     */
    protected function buildPayload(array $qliroOrder, InputInterface $input): ?array
    {
        return [
            'OrderId'           => $qliroOrder['OrderId'] ?? null,
            'MerchantReference' => $qliroOrder['MerchantReference'] ?? null,
            'Status'            => $qliroOrder['Status'] ?? null,
            'Timestamp'         => date('Y-m-d\TH:i:s\Z'),
        ];
    }
}
