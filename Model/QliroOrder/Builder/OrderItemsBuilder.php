<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Framework\Event\ManagerInterface;
use Magento\Tax\Helper\Data as TaxHelper;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Tax\Model\Calculation as TaxCalculation;
use Qliro\QliroOne\Api\Builder\OrderItemHandlerInterface;
use Qliro\QliroOne\Helper\Data as QliroHelper;
use Qliro\QliroOne\Model\Product\Type\QuoteSourceProvider;
use Qliro\QliroOne\Model\Product\Type\TypePoolHandler;

/**
 * QliroOne Order items builder class
 */
class OrderItemsBuilder
{
    /**
     * @var \Magento\Quote\Model\Quote
     */
    protected $quote;

    /**
     * @var \Qliro\QliroOne\Api\Builder\OrderItemHandlerInterface[]
     */
    protected $handlers = [];

    /**
     * Class constructor
     *
     * @param TaxHelper $taxHelper
     * @param TaxCalculation $taxCalculation
     * @param TypePoolHandler $typeResolver
     * @param QliroHelper $qliroHelper
     * @param QuoteSourceProvider $quoteSourceProvider
     * @param ManagerInterface $eventManager
     * @param \Qliro\QliroOne\Api\Builder\OrderItemHandlerInterface[] $handlers
     */
    public function __construct(
        protected readonly TaxHelper $taxHelper,
        protected readonly TaxCalculation $taxCalculation,
        protected readonly TypePoolHandler $typeResolver,
        protected readonly QliroHelper $qliroHelper,
        protected readonly QuoteSourceProvider $quoteSourceProvider,
        protected readonly ManagerInterface $eventManager,
        $handlers = []
    ) {
        $this->handlers = $handlers;
    }

    /**
     * Set quote for data extraction
     *
     * @param \Magento\Quote\Api\Data\CartInterface $quote
     * @return $this
     */
    public function setQuote(CartInterface $quote)
    {
        $this->quote = $quote;
        $this->quoteSourceProvider->setQuote($this->quote);

        return $this;
    }

    /**
     * Create an array of containers
     *
     * @return array[]
     */
    public function create()
    {
        if (empty($this->quote)) {
            throw new \LogicException('Quote entity is not set.');
        }

        $result = [];

        /** @var \Magento\Quote\Model\Quote\Item $item */
        foreach ($this->quote->getAllItems() as $item) {
            $qliroOrderItem = $this->typeResolver->resolveQliroOrderItem(
                $this->quoteSourceProvider->generateSourceItem($item, $item->getQty()),
                $this->quoteSourceProvider
            );

            if ($qliroOrderItem) {
                $this->eventManager->dispatch(
                    'qliroone_order_item_build_after',
                    [
                        'quote' => $this->quote,
                        'container' => $qliroOrderItem,
                    ]
                );

                if (!empty($qliroOrderItem['MerchantReference'] ?? null)) {
                    $result[] = $qliroOrderItem;
                }
            }
        }

        foreach ($this->handlers as $handler) {
            if ($handler instanceof OrderItemHandlerInterface) {
                $result = $handler->handle($result, $this->quote);
            }
        }

        $this->quote = null;
        $this->quoteSourceProvider->setQuote($this->quote);

        return $result;
    }
}
