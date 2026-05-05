<?php

/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address as QuoteAddress;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Tax\Helper\Data as TaxHelper;
use Qliro\QliroOne\Api\Data\QliroOrderShippingMethodInterface;
use Qliro\QliroOne\Api\Data\QliroOrderShippingMethodInterfaceFactory;
use Qliro\QliroOne\Api\ShippingMethodBrandResolverInterface;
use Qliro\QliroOne\Helper\Data;

/**
 * QliroOne Order Item of type "Shipping" builder class
 */
class ShippingMethodBuilder
{
    /**
     * @var \Magento\Quote\Model\Quote\Address\Rate
     */
    private $rate;

    /**
     * @var \Magento\Quote\Model\Quote
     */
    private $quote;

    /**
     * @var \Magento\Tax\Helper\Data
     */
    private $taxHelper;

    /**
     * @var \Qliro\QliroOne\Api\Data\QliroOrderShippingMethodInterfaceFactory
     */
    private $shippingMethodFactory;

    /**
     * @var \Qliro\QliroOne\Api\ShippingMethodBrandResolverInterface
     */
    private $shippingMethodBrandResolver;

    /**
     * @var \Qliro\QliroOne\Helper\Data
     */
    private $qliroHelper;

    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    private $eventManager;

    /**
     * Inject dependencies
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderShippingMethodInterfaceFactory $shippingMethodFactory
     * @param \Magento\Tax\Helper\Data $taxHelper
     * @param \Qliro\QliroOne\Api\ShippingMethodBrandResolverInterface $shippingMethodBrandResolver
     * @param \Qliro\QliroOne\Helper\Data $qliroHelper
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     */
    public function __construct(
        QliroOrderShippingMethodInterfaceFactory $shippingMethodFactory,
        TaxHelper $taxHelper,
        ShippingMethodBrandResolverInterface $shippingMethodBrandResolver,
        Data $qliroHelper,
        ManagerInterface $eventManager
    ) {
        $this->taxHelper = $taxHelper;
        $this->shippingMethodFactory = $shippingMethodFactory;
        $this->shippingMethodBrandResolver = $shippingMethodBrandResolver;
        $this->qliroHelper = $qliroHelper;
        $this->eventManager = $eventManager;
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
     * Set shipping rate for data extraction
     *
     * @param \Magento\Quote\Model\Quote\Address\Rate $rate
     * @return $this
     */
    public function setShippingRate(Rate $rate)
    {
        $this->rate = $rate;

        return $this;
    }

    /**
     * Create a QliroOne order shipping method container
     *
     * @return QliroOrderShippingMethodInterface
     */

    public function create()
    {
        if (empty($this->quote)) {
            throw new \LogicException('Quote entity is not set.');
        }

        if (empty($this->rate)) {
            throw new \LogicException('Shipping rate entity is not set.');
        }

        $shippingAddress = $this->quote->getShippingAddress();
        /** @var QliroOrderShippingMethodInterface $container */
        $container = $this->shippingMethodFactory->create();

        $discountedRatePrice = $this->applyShippingDiscount($shippingAddress, (float) $this->rate->getPrice());

        $priceExVat = $this->taxHelper->getShippingPrice(
            $discountedRatePrice,
            false,
            $shippingAddress,
            $this->quote->getCustomerTaxClassId()
        );

        $priceIncVat = $this->taxHelper->getShippingPrice(
            $discountedRatePrice,
            true,
            $shippingAddress,
            $this->quote->getCustomerTaxClassId()
        );

        $container->setMerchantReference($this->rate->getCode());
        $container->setDisplayName($this->rate->getMethodTitle()?? $this->rate->getCarrierTitle());
        $container->setBrand($this->shippingMethodBrandResolver->resolve($this->rate));

        $descriptions = [];

        if ($this->rate->getCarrierTitle() !== null) {
            $descriptions[] = $this->rate->getCarrierTitle();
        }

        if ($this->rate->getMethodDescription() !== null) {
            $descriptions[] = $this->rate->getMethodDescription();
        }

        if (!empty($descriptions)) {
            $container->setDescriptions($descriptions);
        }

        $container->setPriceIncVat($this->qliroHelper->formatPrice($priceIncVat));
        $container->setPriceExVat($this->qliroHelper->formatPrice($priceExVat));
        $container->setSupportsDynamicSecondaryOptions(false);

        $this->eventManager->dispatch(
            'qliroone_shipping_method_build_after',
            [
                'quote' => $this->quote,
                'rate' => $this->rate,
                'container' => $container,
            ]
        );

        $this->quote = null;
        $this->rate = null;

        return $container;
    }

    /**
     * Apply shipping discount to the rate price
     *
     * @param QuoteAddress $address
     * @param float $ratePrice
     * @return float
     */
    private function applyShippingDiscount(QuoteAddress $address, float $ratePrice): float
    {
        if ($address->getFreeShipping()) {
            return 0.0;
        }

        $discountAmount  = (float) $address->getShippingDiscountAmount();
        $originalSelected = (float) $address->getShippingAmount() + $discountAmount;

        if ($originalSelected <= 0.0 || $discountAmount <= 0.0) {
            return $ratePrice;
        }

        $ratio = $discountAmount / $originalSelected;
        return max(0.0, $ratePrice * (1.0 - $ratio));
    }
}
