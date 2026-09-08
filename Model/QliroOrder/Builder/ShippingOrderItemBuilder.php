<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Framework\App\ObjectManager;
use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Magento\Tax\Api\TaxCalculationInterface;
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
     * @var \Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory
     */
    private $orderItemFactory;

    /**
     * @var \Magento\Tax\Helper\Data
     */
    private $taxHelper;

    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    private $eventManager;

    /**
     * @var \Magento\Tax\Api\TaxCalculationInterface
     */
    private $taxCalculation;

    /**
     * Inject dependencies
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory $orderItemFactory
     * @param \Magento\Tax\Helper\Data $taxHelper
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param \Magento\Tax\Api\TaxCalculationInterface|null $taxCalculation
     */
    public function __construct(
        QliroOrderItemInterfaceFactory $orderItemFactory,
        TaxHelper $taxHelper,
        ManagerInterface $eventManager,
        ?TaxCalculationInterface $taxCalculation = null
    ) {
        $this->orderItemFactory = $orderItemFactory;
        $this->taxHelper = $taxHelper;
        $this->eventManager = $eventManager;
        // Optional so a store that wired this builder up with the old signature keeps working.
        // Magento passes null for optional arguments instead of resolving them
        $this->taxCalculation = $taxCalculation ?: ObjectManager::getInstance()->get(TaxCalculationInterface::class);
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
     * Nothing in the module references this builder, it is kept because removing a public class
     * breaks anyone who wired it up themselves.
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
        // The tax helper rounds both amounts, so the rate is asked for rather than read off them
        $container->setVatRate($this->getVatRate());
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

    /**
     * The rate shipping is taxed with, for the customer and store of the quote
     *
     * @return float
     */
    private function getVatRate(): float
    {
        $storeId = $this->quote->getStoreId();

        return (float)$this->taxCalculation->getCalculatedRate(
            $this->taxHelper->getShippingTaxClass($storeId),
            $this->quote->getCustomerId(),
            $storeId
        );
    }
}
