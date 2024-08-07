<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Product\Type;

use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterface;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterfaceFactory;
use Qliro\QliroOne\Api\Product\TypeSourceProviderInterface;
use Qliro\QliroOne\Model\Product\ProductPool;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Service\RecurringPayments\Data as RecurringDataService;

/**
 * Quote Source Provider class
 */
class QuoteSourceProvider implements TypeSourceProviderInterface
{
    /**
     * @var array
     */
    private $sourceItems = [];

    /**
     * @var \Magento\Quote\Model\Quote
     */
    private $quote;

    /**
     * @var \Qliro\QliroOne\Model\Product\ProductPool
     */
    private $productPool;

    /**
     * @var \Qliro\QliroOne\Api\Product\TypeSourceItemInterfaceFactory
     */
    private $typeSourceItemFactory;

    /**
     * @var \Qliro\QliroOne\Model\Config
     */
    private $config;

    /**
     * @var \Qliro\QliroOne\Service\RecurringPayments\Data
     */
    private $recurringDataService;

    /**
     * Inject dependencies
     *
     * @param \Qliro\QliroOne\Model\Product\ProductPool $productPool
     * @param \Qliro\QliroOne\Api\Product\TypeSourceItemInterfaceFactory $typeSourceItemFactory
     * @param \Qliro\QliroOne\Model\Config $config
     * @param \Qliro\QliroOne\Service\RecurringPayments\Data $recurringDataService
     */
    public function __construct(
        ProductPool $productPool,
        TypeSourceItemInterfaceFactory $typeSourceItemFactory,
        Config $config,
        RecurringDataService $recurringDataService
    ) {
        $this->productPool = $productPool;
        $this->typeSourceItemFactory = $typeSourceItemFactory;
        $this->config = $config;
        $this->recurringDataService = $recurringDataService;
    }

    /**
     * @return int
     */
    public function getStoreId()
    {
        return $this->getStoreId();
    }

    /**
     * @param string $reference
     * @return \Qliro\QliroOne\Api\Product\TypeSourceItemInterface
     */
    public function getSourceItemByMerchantReference($reference)
    {
        if (strpos($reference, ':') !== false) {
            list($quoteItemId, $sku) = explode(':', $reference);
        } else {
            $quoteItemId = null;
            $sku = $reference;
        }

        try {
            $quoteItem = $this->quote->getItemById($quoteItemId);

            if (!$quoteItem) {
                if ($sku) {
                    $product = $this->productPool->getProduct($sku, $this->getStoreId());

                    $quoteItem = $this->quote->getItemByProduct($product);
                } else {
                    $quoteItem = null;
                }
            }

            if ($quoteItem) {
                // Basically, at this point we do not update quote items

                return $this->generateSourceItem($quoteItem, $quoteItem->getQty());
            }

            return null;
        } catch (\Exception $exception) {
            return null;
        }
    }

    /**
     * @return \Qliro\QliroOne\Api\Product\TypeSourceItemInterface[]
     */
    public function getSourceItems()
    {
        $result = [];

        /** @var \Magento\Quote\Model\Quote\Item $item */
        foreach ($this->quote->getAllVisibleItems() as $item) {
            $result[] = $this->generateSourceItem($item, $item->getQty());
        }

        return $result;
    }

    /**
     * Set quote
     *
     * @param \Magento\Quote\Model\Quote $quote
     */
    public function setQuote($quote)
    {
        $this->quote = $quote;
    }

    /**
     * @param \Magento\Quote\Model\Quote\Item $item
     * @param float $quantity
     * @return \Qliro\QliroOne\Api\Product\TypeSourceItemInterface
     */
    public function generateSourceItem($item, $quantity)
    {
        if (!isset($this->sourceItems[$item->getItemId()])) {
            /** @var \Qliro\QliroOne\Api\Product\TypeSourceItemInterface $sourceItem */
            $sourceItem = $this->typeSourceItemFactory->create();

            $sourceItem->setId($item->getItemId());
            $sourceItem->setName($item->getName());
            $sourceItem->setPriceInclTax($item->getRowTotalInclTax() / $quantity); // $item->getPriceInclTax()
            $sourceItem->setPriceExclTax($item->getRowTotal() / $quantity); // $item->getPrice()
            $sourceItem->setQty($item->getQty());
            $sourceItem->setSku($item->getSku());
            $sourceItem->setType($item->getProductType());
            $sourceItem->setProduct($item->getProduct());
            $sourceItem->setItem($item);
            $this->setSubscriptionInSourceItem($sourceItem);

            $this->sourceItems[$item->getItemId()] = $sourceItem;

            if ($parentItem = $item->getParentItem()) {
                $sourceItem->setParent($this->generateSourceItem($parentItem, $quantity));
            }
        }

        return $this->sourceItems[$item->getItemId()];
    }

    /**
     * Sets subscription flag in source item if it has been set as enabled in quote payment
     *
     * @param TypeSourceItemInterface $sourceItem
     * @return void
     */
    private function setSubscriptionInSourceItem(TypeSourceItemInterface $sourceItem): void
    {
        if (!$this->config->isUseRecurring()) {
            return;
        }

        $recurringInfo = $this->recurringDataService->quoteGetter($this->quote);
        $sourceItem->setSubscription(!!$recurringInfo->getEnabled());
    }
}
