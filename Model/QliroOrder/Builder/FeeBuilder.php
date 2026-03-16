<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Model\Config;

/**
 * QliroOne Order Item of type "Fee" builder class
 */
class FeeBuilder
{
    /**
     * @var \Magento\Quote\Model\Quote
     */
    private $quote;

    /**
     * Class constructor
     *
     * @param Config $qliroConfig
     * @param QliroOrderItemInterfaceFactory $qliroOrderItemFactory
     * @param \Qliro\QliroOne\Model\Fee $fee
     * @param ManagerInterface $eventManager
     */
    public function __construct(
        private readonly Config $qliroConfig,
        private readonly QliroOrderItemInterfaceFactory $qliroOrderItemFactory,
        private readonly \Qliro\QliroOne\Model\Fee $fee,
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
     * Create a QliroOne order fee container
     *
     * Is this class used?
     *
     * @return \Qliro\QliroOne\Api\Data\QliroOrderItemInterface
     */
    public function create()
    {
        if (empty($this->quote)) {
            throw new \LogicException('Quote entity is not set.');
        }

        /** @var \Qliro\QliroOne\Api\Data\QliroOrderItemInterface $container */
        $container = $this->qliroOrderItemFactory->create();

        $priceExVat = $this->fee->getQlirooneFeeInclTax($this->quote);
        $priceIncVat = $this->fee->getQlirooneFeeExclTax($this->quote);

        $container->setMerchantReference($this->qliroConfig->getFeeMerchantReference());
        $container->setDescription($this->qliroConfig->getFeeMerchantReference());
        $container->setPricePerItemIncVat($priceIncVat);
        $container->setPricePerItemExVat($priceExVat);
        $container->setQuantity(1);
        $container->setType(QliroOrderItemInterface::TYPE_FEE);

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
