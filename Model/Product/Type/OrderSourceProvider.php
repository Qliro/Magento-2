<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Product\Type;

use Magento\Sales\Model\Order;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterface;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterfaceFactory;
use Qliro\QliroOne\Api\Product\TypeSourceProviderInterface;
use Qliro\QliroOne\Model\Product\ProductPool;
use Qliro\QliroOne\Api\Product\ProductNameResolverInterface;

/**
 * Order Source Provider class
 */
class OrderSourceProvider implements TypeSourceProviderInterface
{
    /**
     * @var array
     */
    private $sourceItems = [];

    /**
     * @var Order
     */
    private $order;

    /**
     * @var ProductPool
     */
    private $productPool;

    /**
     * @var TypeSourceItemInterfaceFactory
     */
    private $typeSourceItemFactory;

    /**
     * @var ProductNameResolverInterface
     */
    private $productNameResolver;

    /**
     * Inject dependencies
     *
     * @param ProductPool $productPool
     * @param TypeSourceItemInterfaceFactory $typeSourceItemFactory
     * @param ProductNameResolverInterface $productNameResolver
     */
    public function __construct(
        ProductPool $productPool,
        TypeSourceItemInterfaceFactory $typeSourceItemFactory,
        ProductNameResolverInterface $productNameResolver
    ) {
        $this->productPool = $productPool;
        $this->typeSourceItemFactory = $typeSourceItemFactory;
        $this->productNameResolver = $productNameResolver;
    }

    /**
     * @return int
     */
    public function getStoreId()
    {
        return $this->order->getStoreId();
    }

    /**
     * @param string $reference
     * @return TypeSourceItemInterface
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
            $orderItem = $this->order->getItemByQuoteItemId($quoteItemId);

            if (!$orderItem) {
                if ($sku) {
                    $product = $this->productPool->getProduct($sku, $this->getStoreId());

                    $orderItem = $this->order->getItemById($product);
                } else {
                    $orderItem = null;
                }
            }

            if ($orderItem) {
                return $this->generateSourceItem($orderItem, $orderItem->getQty());
            }

            return null;
        } catch (\Exception $exception) {
            return null;
        }
    }

    /**
     * @return TypeSourceItemInterface[]
     */
    public function getSourceItems()
    {
        $result = [];

        /** @var \Magento\Sales\Model\Order\Item $item */
        foreach ($this->order->getAllVisibleItems() as $item) {
            $result[] = $this->generateSourceItem($item, $item->getQtyOrdered());
        }

        return $result;
    }

    /**
     * Set order
     *
     * @param Order $order
     */
    public function setOrder($order)
    {
        $this->order = $order;
    }

    /**
     * @param \Magento\Sales\Model\Order\Item $item
     * @param float $quantity
     * @return TypeSourceItemInterface
     */
    public function generateSourceItem($item, $quantity)
    {
        if (!isset($this->sourceItems[$item->getQuoteItemId()])) {
            /** @var TypeSourceItemInterface $sourceItem */
            $sourceItem = $this->typeSourceItemFactory->create();

            $sourceItem->setId($item->getQuoteItemId());
            $sourceItem->setName($this->productNameResolver->getName($item));
            $sourceItem->setPriceInclTax(($item->getRowTotalInclTax() - $item->getDiscountAmount()) / $item->getQtyOrdered());
            $sourceItem->setPriceExclTax(($item->getRowTotalInclTax() - $item->getDiscountAmount() - $item->getTaxAmount()) / $item->getQtyOrdered());
            $sourceItem->setQty($item->getQtyOrdered());
            $sourceItem->setSku($item->getSku());
            $sourceItem->setType($item->getProductType());
            $sourceItem->setProduct($item->getProduct());
            $sourceItem->setItem($item);

            $this->sourceItems[$item->getQuoteItemId()] = $sourceItem;

            if ($parentItem = $item->getParentItem()) {
                $sourceItem->setParent($this->generateSourceItem($parentItem, $quantity));
            }
        }

        return $this->sourceItems[$item->getQuoteItemId()];
    }
}
