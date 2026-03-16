<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Model\Config;

/**
 * QliroOne Order update request builder class
 *
 * Builds the payload for PATCH/PUT requests to Qliro when the quote changes
 * (e.g. customer enters address, shipping method changes, items change).
 */
class UpdateRequestBuilder
{
    /**
     * @var Quote|null
     */
    private ?Quote $quote = null;

    /**
     * Class constructor
     *
     * @param Config $qliroConfig
     * @param OrderItemsBuilder $orderItemsBuilder
     * @param ShippingMethodsBuilder $shippingMethodsBuilder
     * @param ShippingConfigBuilder $shippingConfigBuilder
     */
    public function __construct(
        private readonly Config $qliroConfig,
        private readonly OrderItemsBuilder $orderItemsBuilder,
        private readonly ShippingMethodsBuilder $shippingMethodsBuilder,
        private readonly ShippingConfigBuilder $shippingConfigBuilder
    ) {
    }

    /**
     * Set the quote to build the update request from.
     */
    public function setQuote(Quote $quote): self
    {
        $this->quote = $quote;

        return $this;
    }

    /**
     * Build and return the update request payload array.
     *
     * @throws \LogicException
     */
    public function create(): array
    {
        if (empty($this->quote)) {
            throw new \LogicException('Quote entity is not set.');
        }

        $updateRequest = [
            'RequireIdentityVerification' => (bool) $this->qliroConfig->requireIdentityVerification(),
        ];

        $updateRequest['OrderItems'] = $this->orderItemsBuilder->setQuote($this->quote)->create();

        $shippingMethods = $this->shippingMethodsBuilder->setQuote($this->quote)->create();
        if (isset($shippingMethods['AvailableShippingMethods'])) {
            $updateRequest['AvailableShippingMethods'] = $shippingMethods['AvailableShippingMethods'];
        }

        $shippingConfig = $this->shippingConfigBuilder->setQuote($this->quote)->create();
        if ($shippingConfig) {
            $updateRequest['ShippingConfiguration'] = $shippingConfig;
        }

        $this->quote = null;

        return $updateRequest;
    }
}
