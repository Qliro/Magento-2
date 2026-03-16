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
 * Simulate a Qliro ShippingMethods callback locally.
 *
 * Qliro sends this to MerchantOrderAvailableShippingMethodsUrl whenever the
 * customer changes their delivery address and the store needs to return the
 * updated list of available shipping methods.
 *
 * The handler (Management\ShippingMethod::get) reads:
 *   OrderId
 *   CountryCode
 *   ShippingAddress   (used by QuoteFromShippingMethodsConverter)
 *   Customer          (used by QuoteFromShippingMethodsConverter)
 *
 * The converter also reads ShippingAddress.PostalCode for address matching.
 *
 * Usage:
 *   bin/magento qliroone:callback:shipping-methods <qliro_order_id>
 *   bin/magento qliroone:callback:shipping-methods <qliro_order_id> --dry-run
 */
class ShippingMethodsCommand extends AbstractCallbackCommand
{
    protected function getCommandName(): string
    {
        return 'qliroone:callback:shipping-methods';
    }

    protected function getCommandDescription(): string
    {
        return '[Dev] Simulate a Qliro ShippingMethods callback to the local store';
    }

    protected function getCallbackPath(): string
    {
        return 'checkout/qliro_callback/shippingMethods';
    }

    /**
     * Payload mirrors what Qliro sends to MerchantOrderAvailableShippingMethodsUrl.
     *
     * The converter reads: OrderId, CountryCode, ShippingAddress, Customer.
     * CountryCode falls back to the top-level Country field on the order.
     * ShippingAddress falls back to BillingAddress when not present.
     */
    protected function buildPayload(array $qliroOrder, InputInterface $input): ?array
    {
        $shippingAddress = $qliroOrder['ShippingAddress'] ?? $qliroOrder['BillingAddress'] ?? [];

        return [
            'OrderId'         => $qliroOrder['OrderId'] ?? null,
            'MerchantReference' => $qliroOrder['MerchantReference'] ?? null,
            'CountryCode'     => $qliroOrder['Country'] ?? $shippingAddress['CountryCode'] ?? null,
            'ShippingAddress' => $shippingAddress,
            'Customer'        => $qliroOrder['Customer'] ?? [],
        ];
    }
}
