<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

// @codingStandardsIgnoreFile
// phpcs:ignoreFile

namespace Qliro\QliroOne\Console\Callback;

use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Simulate a Qliro MerchantNotification push callback locally.
 *
 * Qliro sends this to MerchantNotificationUrl for out-of-band events that do
 * not map to the checkout or transaction flows.
 *
 * The only event type currently handled is ShippingProviderUpdate, sent by
 * Unifaun/nShift or Ingrid after the carrier has processed the shipment.
 *
 * The handler (Management\MerchantNotification::execute) reads:
 *   OrderId, MerchantReference, EventType, Provider, Payload
 *
 * For ShippingProviderUpdate Payload is a nested structure whose shape depends
 * on the provider:
 *   Unifaun → Payload.service.name, Payload.service.id
 *   Ingrid  → Payload.session.delivery_groups[0].shipping.carrier,
 *              Payload.session.delivery_groups[0].shipping.carrier_product_id
 *
 * Usage:
 *   bin/magento qliroone:callback:merchant-notification <qliro_order_id>
 *   bin/magento qliroone:callback:merchant-notification <qliro_order_id> --provider=Unifaun
 *   bin/magento qliroone:callback:merchant-notification <qliro_order_id> --provider=Ingrid
 *   bin/magento qliroone:callback:merchant-notification <qliro_order_id> --dry-run
 */
class MerchantNotificationCommand extends AbstractCallbackCommand
{
    protected function getCommandName(): string
    {
        return 'qliroone:callback:merchant-notification';
    }

    protected function getCommandDescription(): string
    {
        return '[Dev] Simulate a Qliro MerchantNotification push to the local store';
    }

    protected function getCallbackPath(): string
    {
        return 'checkout/qliro_callback/merchantNotification';
    }

    protected function configureExtra(): void
    {
        $this->addOption(
            'provider',
            null,
            InputOption::VALUE_OPTIONAL,
            'Shipping provider for ShippingProviderUpdate: Unifaun|Ingrid. '
                . 'Determines the stub Payload shape. Defaults to Unifaun.',
            'Unifaun'
        );
    }

    /**
     * Payload mirrors what Qliro sends to MerchantNotificationUrl.
     *
     * The management class reads: OrderId, MerchantReference, EventType, Provider, Payload.
     *
     * The Payload stub matches the minimum structure that
     * Management\MerchantNotification::shippingProviderUpdate reads to build
     * the shipping description on the Magento order.
     */
    protected function buildPayload(array $qliroOrder, InputInterface $input): ?array
    {
        $provider = $input->getOption('provider');

        if ($provider === 'Ingrid') {
            $stubPayload = [
                'session' => [
                    'delivery_groups' => [
                        [
                            'shipping' => [
                                'carrier'            => 'Ingrid Carrier',
                                'carrier_product_id' => 'ingrid-express',
                            ],
                        ],
                    ],
                ],
            ];
        } else {
            // Default: Unifaun / nShift
            $provider    = 'Unifaun';
            $stubPayload = [
                'service' => [
                    'name' => 'Unifaun Standard',
                    'id'   => 'unifaun-standard',
                ],
            ];
        }

        return [
            'OrderId'           => $qliroOrder['OrderId'] ?? null,
            'MerchantReference' => $qliroOrder['MerchantReference'] ?? null,
            'EventType'         => 'ShippingProviderUpdate',
            'Provider'          => $provider,
            'Payload'           => $stubPayload,
        ];
    }
}
