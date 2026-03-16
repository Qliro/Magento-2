<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Tax\Helper\Data as TaxHelper;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;

/**
 * QliroOne Order Item of type "Shipping" builder class
 */
class ShippingOrderItemBuilder
{
    /**
     * @var \Magento\Quote\Model\Quote
     */
    private $quote;

    /**
     * Class constructor
     *
     * @param QliroOrderItemInterfaceFactory $orderItemFactory
     * @param TaxHelper $taxHelper
     * @param ManagerInterface $eventManager
     */
    public function __construct(
        private readonly QliroOrderItemInterfaceFactory $orderItemFactory,
        private readonly TaxHelper $taxHelper,
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
     * Create a QliroOne order item container for a shipping method
     *
     * @return \Qliro\QliroOne\Api\Data\QliroOrderItemInterface
     */
    public function create()
    {
        if (empty($this->quote)) {
            throw new \LogicException('Quote entity is not set.');
        }

        $shippingAddress = $this->quote->getShippingAddress();
        $code = $shippingAddress->getShippingMethod();
        $rate = $shippingAddress->getShippingRateByCode($code);

        /** @var \Qliro\QliroOne\Api\Data\QliroOrderItemInterface $container */
        $container = $this->orderItemFactory->create();

        $priceExVat = $this->taxHelper->getShippingPrice(
            $rate->getPrice(),
            false,
            $shippingAddress,
            $this->quote->getCustomerTaxClassId()
        );

        $priceIncVat = $this->taxHelper->getShippingPrice(
            $rate->getPrice(),
            true,
            $shippingAddress,
            $this->quote->getCustomerTaxClassId()
        );

        $container->setMerchantReference($code);
        $container->setType(\Qliro\QliroOne\Api\Data\QliroOrderItemInterface::TYPE_SHIPPING);
        $container->setQuantity(1);
        $container->setPricePerItemIncVat($priceIncVat);
        $container->setPricePerItemExVat($priceExVat);
        $container->setDescription($rate->getMethodTitle());

        $this->eventManager->dispatch(
            'qliroone_order_item_build_after',
            [
                'quote' => $this->quote,
                'container' => $container,
            ]
        );

        $this->quote = null;

        return $container;
    }
}
