<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Product\Type;

use Magento\Sales\Model\Order;
use Magento\Tax\Helper\Data as TaxHelper;
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
    private array $sourceItems = [];
    private ?Order $order = null;

    /**
     * Class constructor
     *
     * @param ProductPool $productPool
     * @param TypeSourceItemInterfaceFactory $typeSourceItemFactory
     * @param ProductNameResolverInterface $productNameResolver
     * @param TaxHelper $taxHelper
     */
    public function __construct(
        private readonly ProductPool $productPool,
        private readonly TypeSourceItemInterfaceFactory $typeSourceItemFactory,
        private readonly ProductNameResolverInterface $productNameResolver,
        private readonly TaxHelper $taxHelper
    ) {
    }

    /**
     * @return int
     */
    public function getStoreId(): int
    {
        return (int)$this->order->getStoreId();
    }

    /**
     * @param mixed $reference
     * @return TypeSourceItemInterface|null
     */
    public function getSourceItemByMerchantReference(mixed $reference): ?TypeSourceItemInterface
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
    public function getSourceItems(): array
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
    public function setOrder(?Order $order): void
    {
        $this->order = $order;
    }

    /**
     * @param \Magento\Sales\Model\Order\Item $item
     * @param float $quantity
     * @return TypeSourceItemInterface
     */
    public function generateSourceItem(mixed $item, float $quantity): TypeSourceItemInterface
    {
        if (!isset($this->sourceItems[$item->getQuoteItemId()])) {
            /** @var TypeSourceItemInterface $sourceItem */
            $sourceItem = $this->typeSourceItemFactory->create();

            $sourceItem->setId($item->getQuoteItemId());
            $sourceItem->setName($this->productNameResolver->getName($item));
            if ($this->taxHelper->discountTax($item->getStore())) {
                $sourceItem->setPriceInclTax(
                    ($item->getRowTotalInclTax() - $item->getDiscountAmount()) / $item->getQtyOrdered()
                );
                $sourceItem->setPriceExclTax(
                    ($item->getRowTotalInclTax() - $item->getDiscountAmount() - $item->getTaxAmount()) / $item->getQtyOrdered()
                );
            } else {
                $sourceItem->setPriceInclTax(
                    ($item->getRowTotal() - $item->getDiscountAmount() + $item->getTaxAmount()) / $item->getQtyOrdered()
                );
                $sourceItem->setPriceExclTax(
                    ($item->getRowTotal() - $item->getDiscountAmount()) / $item->getQtyOrdered()
                );
            }

            $sourceItem->setQty((float) $item->getQtyOrdered());
            // Configurable parent items can have a null SKU (the SKU lives on the simple child).
            // Fall back to the parent's SKU when the item itself has none, then to an empty
            // string so downstream code never receives null.
            $sourceItem->setSku(
                $item->getSku() ?? $item->getParentItem()?->getSku() ?? ''
            );
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
