<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Framework\Event\ManagerInterface;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Fee;

/**
 * QliroOne Order Item of type "Fee" builder class
 */
class FeeBuilder
{
    private ?Quote $quote = null;

    /**
     * Class constructor
     *
     * @param Config $qliroConfig
     * @param QliroOrderItemInterfaceFactory $qliroOrderItemFactory
     * @param Fee $fee
     * @param ManagerInterface $eventManager
     */
    public function __construct(
        private readonly Config $qliroConfig,
        private readonly QliroOrderItemInterfaceFactory $qliroOrderItemFactory,
        private readonly Fee $fee,
        private readonly ManagerInterface $eventManager
    ) {
    }

    /**
     * Set quote for data extraction
     *
     * @param Quote $quote
     * @return $this
     */
    public function setQuote(Quote $quote): static
    {
        $this->quote = $quote;

        return $this;
    }

    /**
     * Create a QliroOne order fee container
     *
     * Is this class used?
     *
     * @return QliroOrderItemInterface
     */
    public function create(): QliroOrderItemInterface
    {
        if (empty($this->quote)) {
            throw new \LogicException('Quote entity is not set.');
        }

        /** @var QliroOrderItemInterface $container */
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
