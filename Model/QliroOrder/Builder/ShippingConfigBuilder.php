<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Model\Config;

/**
 * Shipping Config Builder class
 */
class ShippingConfigBuilder
{
    /**
     * @var \Magento\Quote\Model\Quote
     */
    private $quote;

    /**
     * Class constructor
     *
     * @param ShippingConfigUnifaunBuilder $shippingConfigUnifaunBuilder
     * @param ManagerInterface $eventManager
     * @param Config $qliroConfig
     */
    public function __construct(
        private readonly ShippingConfigUnifaunBuilder $shippingConfigUnifaunBuilder,
        private readonly ManagerInterface $eventManager,
        private readonly Config $qliroConfig
    ) {
    }

    /**
     * Set quote for data extraction
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return $this
     */
    public function setQuote(Quote $quote)
    {
        $this->quote = $quote;

        return $this;
    }

    /**
     * Create a QliroOne order shipping Config container
     *
     * @return \Qliro\QliroOne\Api\Data\QliroOrderShippingConfigInterface
     */
    public function create()
    {
        if (empty($this->quote)) {
            throw new \LogicException('Quote entity is not set.');
        }
        if (!$this->qliroConfig->isUnifaunEnabled($this->quote->getStoreId())) {
            return null;
        }
        if ($this->quote->isVirtual()) {
            return null;
        }

        $unifaunContainer = $this->shippingConfigUnifaunBuilder->setQuote($this->quote)->create();
        $container = ['Unifaun' => $unifaunContainer];

        $this->eventManager->dispatch(
            'qliroone_shipping_config_build_after',
            [
                'quote' => $this->quote,
                'container' => &$container,
            ]
        );

        $this->quote = null;

        return $container;
    }
}
