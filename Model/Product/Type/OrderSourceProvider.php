<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Product\Type;

use Magento\Sales\Model\Order;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterface;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterfaceFactory;
use Qliro\QliroOne\Api\Product\TypeSourceProviderInterface;
use Qliro\QliroOne\Model\Product\ProductPool;
use Qliro\QliroOne\Model\QliroOrder\LineReference;

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
     * @var LineReference
     */
    private $lineReference;

    /**
     * Inject dependencies
     *
     * @param ProductPool $productPool
     * @param TypeSourceItemInterfaceFactory $typeSourceItemFactory
     * @param LineReference|null $lineReference
     */
    public function __construct(
        ProductPool $productPool,
        TypeSourceItemInterfaceFactory $typeSourceItemFactory,
        ?LineReference $lineReference = null
    ) {
        $this->productPool = $productPool;
        $this->typeSourceItemFactory = $typeSourceItemFactory;
        $this->lineReference = $lineReference ?? new LineReference();
    }

    /**
     * @return int
     */
    public function getStoreId()
    {
        return $this->order->getStoreId();
    }

    /**
     * Find the order item a line reference stands for: a string, or line metadata holding it under quoteItems
     *
     * @param string|array $reference
     * @return TypeSourceItemInterface|null
     */
    public function getSourceItemByMerchantReference($reference)
    {
        $reference = $this->lineReference->fromMetadata($reference);

        if ($reference === null) {
            return null;
        }

        $quoteItemId = $this->lineReference->itemIdOf($reference);
        $sku = $this->lineReference->skuOf($reference);

        try {
            $orderItem = $quoteItemId ? $this->order->getItemByQuoteItemId($quoteItemId) : null;

            if (!$orderItem && $sku !== '') {
                $orderItem = $this->getItemBySku($sku);
            }

            if ($orderItem && $this->hasProducts($orderItem)) {
                return $this->generateSourceItem($orderItem, $orderItem->getQtyOrdered());
            }

            return null;
        } catch (\Exception $exception) {
            return null;
        }
    }

    /**
     * An item without children placed with the sku, then the item of the product that has the sku now,
     * then any item placed with the sku. Never one whose product is gone
     *
     * @param string $sku
     * @return \Magento\Sales\Model\Order\Item|null
     */
    private function getItemBySku(string $sku)
    {
        $items = $this->order->getAllItems();
        $bySku = null;

        foreach ($items as $item) {
            if ((string)$item->getSku() === $sku && $this->hasProducts($item)) {
                if (!$item->getChildrenItems()) {
                    return $item;
                }
                $bySku = $bySku ?? $item;
            }
        }

        try {
            $productId = (int)$this->productPool->getProduct($sku, $this->getStoreId())->getId();
        } catch (\Exception $exception) {
            $productId = 0;
        }

        foreach ($items as $item) {
            if ($productId && (int)$item->getProductId() === $productId && $this->hasProducts($item)) {
                return $item;
            }
        }

        return $bySku;
    }

    /**
     * The handlers read the product of the item and of its parent
     *
     * @param \Magento\Sales\Model\Order\Item $item
     * @return bool
     */
    private function hasProducts($item): bool
    {
        $parent = $item->getParentItem();

        return $item->getProduct() && (!$parent || $parent->getProduct());
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
     * @param Order|null $order
     */
    public function setOrder(?Order $order)
    {
        $this->order = $order;
        $this->sourceItems = [];
    }

    /**
     * @param \Magento\Sales\Model\Order\Item $item
     * @param float $quantity
     * @return TypeSourceItemInterface
     */
    public function generateSourceItem($item, $quantity)
    {
        $key = $item->getId() ?: 'object-' . spl_object_id($item);

        if (!isset($this->sourceItems[$key])) {
            /** @var TypeSourceItemInterface $sourceItem */
            $sourceItem = $this->typeSourceItemFactory->create();

            $sourceItem->setId((int)$item->getQuoteItemId());
            $sourceItem->setName($item->getName());
            $sourceItem->setPriceInclTax((float)$item->getPriceInclTax());
            $sourceItem->setPriceExclTax((float)$item->getPrice());
            $sourceItem->setQty((float)$item->getQtyOrdered());
            $sku = $item->getSku() ?? $item->getProduct()?->getSku() ?? '';
            $sourceItem->setSku((string)$sku);
            $sourceItem->setType($item->getProductType());
            $sourceItem->setProduct($item->getProduct());
            $sourceItem->setItem($item);

            $this->sourceItems[$key] = $sourceItem;

            if ($parentItem = $item->getParentItem()) {
                $sourceItem->setParent($this->generateSourceItem($parentItem, $quantity));
            }
        }

        return $this->sourceItems[$key];
    }
}
