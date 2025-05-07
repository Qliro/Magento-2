<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Store\Model\StoreManagerInterface;
use Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterface;
use Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterfaceFactory;
use Qliro\QliroOne\Model\Config;

/**
 * Shipping Methods Builder class
 */
class ShippingMethodsBuilder
{
    /**
     * @var \Magento\Quote\Model\Quote
     */
    private $quote;

    /**
     * @var \Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterfaceFactory
     */
    private $shippingMethodsResponseFactory;

    /**
     * @var \Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodBuilder
     */
    private $shippingMethodBuilder;

    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    private $eventManager;
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Config
     */
    private $qliroConfig;

    /**
     * Inject dependencies
     *
     * @param \Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterfaceFactory $shippingMethodsResponseFactory
     * @param \Qliro\QliroOne\Model\QliroOrder\Builder\ShippingMethodBuilder $shippingMethodBuilder
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param StoreManagerInterface $storeManager
     * @param Config $qliroConfig
     */
    public function __construct(
        UpdateShippingMethodsResponseInterfaceFactory $shippingMethodsResponseFactory,
        ShippingMethodBuilder $shippingMethodBuilder,
        ManagerInterface $eventManager,
        StoreManagerInterface $storeManager,
        Config $qliroConfig
    ) {
        $this->shippingMethodsResponseFactory = $shippingMethodsResponseFactory;
        $this->shippingMethodBuilder = $shippingMethodBuilder;
        $this->eventManager = $eventManager;
        $this->storeManager = $storeManager;
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
     * @return \Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterface
     */
    public function create()
    {
        if (empty($this->quote)) {
            throw new \LogicException('Quote entity is not set.');
        }

        /** @var \Qliro\QliroOne\Api\Data\UpdateShippingMethodsResponseInterface $container */
        $container = $this->shippingMethodsResponseFactory->create();

        if ($this->qliroConfig->isUnifaunEnabled($this->quote->getStoreId())) {
            return $container;
        }

        $this->quote->getShippingAddress()->setCollectShippingRates(true)->collectShippingRates();
        $shippingAddress = $this->quote->getShippingAddress();
        $this->quote->collectTotals();
        $rateGroups = $shippingAddress->getGroupedAllShippingRates();

        $collectedShippingMethods = [];

        if ($this->quote->getIsVirtual()) {
            $container->setAvailableShippingMethods($collectedShippingMethods);
        } else {
            foreach ($rateGroups as $group) {
                /** @var \Magento\Quote\Model\Quote\Address\Rate $rate */
                foreach ($group as $rate) {
                    if (substr($rate->getCode(), -6) === '_error') {
                        continue;
                    }

                    $this->shippingMethodBuilder->setQuote($this->quote);

                    /** @var \Magento\Store\Api\Data\StoreInterface */
                    $store = $this->storeManager->getStore();
                    $amountPrice = $store->getBaseCurrency()
                        ->convert($rate->getPrice(), $store->getCurrentCurrencyCode());
                    $rate->setPrice($amountPrice);

                    $this->shippingMethodBuilder->setShippingRate($rate);
                    $shippingMethodContainer = $this->shippingMethodBuilder->create();

                    if (!$shippingMethodContainer->getMerchantReference()) {
                        continue;
                    }

                    $collectedShippingMethods[] = $shippingMethodContainer;
                }
            }

            if (empty($collectedShippingMethods)) {
                $container->setDeclineReason(UpdateShippingMethodsResponseInterface::REASON_POSTAL_CODE);
            } else {
                $container->setAvailableShippingMethods($collectedShippingMethods);
            }
        }

        $this->eventManager->dispatch(
            'qliroone_shipping_methods_response_build_after',
            [
                'quote' => $this->quote,
                'container' => $container,
            ]
        );

        $this->quote = null;

        return $container;
    }
}
