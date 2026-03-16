<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Tax\Helper\Data as TaxHelper;
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
     * Class constructor
     *
     * @param TaxHelper $taxHelper
     * @param ShippingMethodBrandResolverInterface $shippingMethodBrandResolver
     * @param Data $qliroHelper
     * @param ManagerInterface $eventManager
     */
    public function __construct(
        private readonly TaxHelper $taxHelper,
        private readonly ShippingMethodBrandResolverInterface $shippingMethodBrandResolver,
        private readonly Data $qliroHelper,
        private readonly ManagerInterface $eventManager
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
     * @return array
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

        $priceExVat = $this->taxHelper->getShippingPrice(
            $this->rate->getPrice() -  $shippingAddress->getShippingDiscountAmount(),
            false,
            $shippingAddress,
            $this->quote->getCustomerTaxClassId()
        );

        $priceIncVat = $this->taxHelper->getShippingPrice(
            $this->rate->getPrice() - $shippingAddress->getShippingDiscountAmount(),
            true,
            $shippingAddress,
            $this->quote->getCustomerTaxClassId()
        );

        $container = [
            'MerchantReference' => (string)$this->rate->getCode(),
            'DisplayName' => (string)($this->rate->getMethodTitle() ?? $this->rate->getCarrierTitle()),
            'Brand' => (string)$this->shippingMethodBrandResolver->resolve($this->rate),
        ];

        $descriptions = [];

        if ($this->rate->getCarrierTitle() !== null) {
            $descriptions[] = $this->rate->getCarrierTitle();
        }

        if ($this->rate->getMethodDescription() !== null) {
            $descriptions[] = $this->rate->getMethodDescription();
        }

        if (!empty($descriptions)) {
            $container['Descriptions'] = $descriptions;
        }

        $container['PriceIncVat'] = (float)$this->qliroHelper->formatPrice($priceIncVat);
        $container['PriceExVat'] = (float)$this->qliroHelper->formatPrice($priceExVat);
        $container['SupportsDynamicSecondaryOptions'] = false;

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
}
