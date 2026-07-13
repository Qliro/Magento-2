<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address\Rate;
use Magento\Tax\Helper\Data as TaxHelper;
use Qliro\QliroOne\Api\ShippingMethodBrandResolverInterface;
use Qliro\QliroOne\Model\Formatter\PriceFormatter;

/**
 * QliroOne Order Item of type "Shipping" builder class
 */
class ShippingMethodBuilder
{
    private ?Rate $rate = null;
    private ?Quote $quote = null;

    /**
     * Class constructor
     *
     * @param TaxHelper $taxHelper
     * @param ShippingMethodBrandResolverInterface $shippingMethodBrandResolver
     * @param PriceFormatter $priceFormatter
     * @param ManagerInterface $eventManager
     */
    public function __construct(
        private readonly TaxHelper                            $taxHelper,
        private readonly ShippingMethodBrandResolverInterface $shippingMethodBrandResolver,
        private readonly PriceFormatter                       $priceFormatter,
        private readonly ManagerInterface                     $eventManager
    ) {
    }

    /**
     * Set quote for data extraction
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return $this
     */
    public function setQuote(Quote $quote): static
    {
        $this->quote = $quote;

        return $this;
    }

    /**
     * Set shipping rate for data extraction
     *
     * @param \Magento\Quote\Model\Quote\Address\Rate $rate
     * @return static
     */
    public function setShippingRate(Rate $rate): static
    {
        $this->rate = $rate;

        return $this;
    }

    /**
     * Create a QliroOne order shipping method container
     *
     * @return array
     */
    public function create(): array
    {
        if (empty($this->quote)) {
            throw new \LogicException('Quote entity is not set.');
        }

        if (empty($this->rate)) {
            throw new \LogicException('Shipping rate entity is not set.');
        }

        $shippingAddress = $this->quote->getShippingAddress();

        $discountAmount = ($this->rate->getCode() === $shippingAddress->getShippingMethod())
            ? (float)$shippingAddress->getShippingDiscountAmount()
            : 0.0;
        $ratePrice = max(0.0, (float)$this->rate->getPrice() - $discountAmount);

        $priceExVat = $this->taxHelper->getShippingPrice(
            $ratePrice,
            false,
            $shippingAddress,
            $this->quote->getCustomerTaxClassId()
        );

        $priceIncVat = $this->taxHelper->getShippingPrice(
            $ratePrice,
            true,
            $shippingAddress,
            $this->quote->getCustomerTaxClassId()
        );

        $container = [
            'MerchantReference' => (string)$this->rate->getCode(),
            'DisplayName' => (string)($this->rate->getMethodTitle() ?? $this->rate->getCarrierTitle()),
            'Brand' => mb_substr((string)$this->shippingMethodBrandResolver->resolve($this->rate), 0, 50),
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

        $container['PriceIncVat'] = (float)$this->priceFormatter->format($priceIncVat);
        $container['PriceExVat'] = (float)$this->priceFormatter->format($priceExVat);
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
