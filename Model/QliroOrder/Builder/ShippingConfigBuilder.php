<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\Data\QliroOrderShippingConfigInterfaceFactory;
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
     * @var \Qliro\QliroOne\Api\Data\QliroOrderShippingConfigInterfaceFactory
     */
    private $shippingConfigFactory;

    /**
     * @var ShippingConfigUnifaunBuilder
     */
    private $shippingConfigUnifaunBuilder;

    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    private $eventManager;

    /**
     * @var Config
     */
    private $qliroConfig;
    /**
     * Inject dependencies
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderShippingConfigInterfaceFactory $shippingConfigFactory
     * @param ShippingConfigUnifaunBuilder $shippingConfigUnifaunBuilder
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param Config $qliroConfig
     */
    public function __construct(
        QliroOrderShippingConfigInterfaceFactory $shippingConfigFactory,
        ShippingConfigUnifaunBuilder $shippingConfigUnifaunBuilder,
        ManagerInterface $eventManager,
        Config $qliroConfig
    ) {
        $this->shippingConfigFactory = $shippingConfigFactory;
        $this->shippingConfigUnifaunBuilder = $shippingConfigUnifaunBuilder;
        $this->eventManager = $eventManager;
        $this->qliroConfig = $qliroConfig;
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

        /** @var \Qliro\QliroOne\Api\Data\QliroOrderShippingConfigInterface $container */
        $container = $this->shippingConfigFactory->create();
        $unifaunContainer = $this->shippingConfigUnifaunBuilder->setQuote($this->quote)->create();
        $container->setUnifaun($unifaunContainer);

        $this->eventManager->dispatch(
            'qliroone_shipping_config_build_after',
            [
                'quote' => $this->quote,
                'container' => $container,
            ]
        );

        $this->quote = null;

        return $container;
    }
}
