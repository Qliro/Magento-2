<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Product\Type\Handler;

use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Api\Product\TypeHandlerInterface;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterface;
use Qliro\QliroOne\Api\Product\TypeSourceProviderInterface;
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Product\ProductPool;
use Qliro\QliroOne\Model\Product\Type\TypeResolver;
use Qliro\QliroOne\Model\Config;

/**
 * Default product type handler class
 */
class DefaultHandler implements TypeHandlerInterface
{
    /**
     * @var \Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory
     */
    private $qliroOrderItemFactory;

    /**
     * @var \Qliro\QliroOne\Model\Product\Type\TypeResolver
     */
    private $typeResolver;

    /**
     * @var \Qliro\QliroOne\Model\Product\ProductPool
     */
    private $productPool;

    /**
     * @var \Qliro\QliroOne\Helper\Data
     */
    private $qliroHelper;

    /**
     * @var \Qliro\QliroOne\Model\Config
     */
    private $config;

    /**
     * Inject dependencies
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory $qliroOrderItemFactory
     * @param \Qliro\QliroOne\Model\Product\Type\TypeResolver $typeResolver
     * @param \Qliro\QliroOne\Model\Product\ProductPool $productPool
     * @param \Qliro\QliroOne\Helper\Data $qliroHelper
     * @param \Qliro\QliroOne\Model\Config $config
     */
    public function __construct(
        QliroOrderItemInterfaceFactory $qliroOrderItemFactory,
        TypeResolver $typeResolver,
        ProductPool $productPool,
        Data $qliroHelper,
        Config $config
    ) {
        $this->qliroOrderItemFactory = $qliroOrderItemFactory;
        $this->typeResolver = $typeResolver;
        $this->productPool = $productPool;
        $this->qliroHelper = $qliroHelper;
        $this->config = $config;
    }

    /**
     * Get QliroOne order item out of a source item, or null if not applicable
     *
     * @param \Qliro\QliroOne\Api\Product\TypeSourceItemInterface $item
     * @return \Qliro\QliroOne\Api\Data\QliroOrderItemInterface|null
     */
    public function getQliroOrderItem(TypeSourceItemInterface $item)
    {
        /** @var \Qliro\QliroOne\Api\Data\QliroOrderItemInterface $qliroOrderItem */
        $qliroOrderItem = $this->qliroOrderItemFactory->create();

        $pricePerItemIncVat = $this->preparePrice($item, true);
        $pricePerItemExVat = $this->preparePrice($item, false);

        $qliroOrderItem->setMerchantReference($this->prepareMerchantReference($item));
        $qliroOrderItem->setType(QliroOrderItemInterface::TYPE_PRODUCT);
        $qliroOrderItem->setQuantity($this->prepareQuantity($item));
        $qliroOrderItem->setPricePerItemIncVat($this->qliroHelper->formatPrice($pricePerItemIncVat));
        $qliroOrderItem->setPricePerItemExVat($this->qliroHelper->formatPrice($pricePerItemExVat));
        $qliroOrderItem->setDescription($this->prepareDescription($item));
        $qliroOrderItem->setMetaData($this->prepareMetaData($item));

        return $qliroOrderItem;
    }

    /**
     * Get a reference to source item out of QliroOne order item, or null if not applicable
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderItemInterface $qliroOrderItem
     * @param \Qliro\QliroOne\Api\Product\TypeSourceProviderInterface $typeSourceProvider
     * @return \Qliro\QliroOne\Api\Product\TypeSourceItemInterface|null
     */
    public function getItem(QliroOrderItemInterface $qliroOrderItem, TypeSourceProviderInterface $typeSourceProvider)
    {
        if ($qliroOrderItem->getType() !== QliroOrderItemInterface::TYPE_PRODUCT) {
            return null;
        }

        return $typeSourceProvider->getSourceItemByMerchantReference($qliroOrderItem->getMerchantReference());
    }

    /**
     * Prepare QliroOne order item's merchant reference
     *
     * @param \Qliro\QliroOne\Api\Product\TypeSourceItemInterface $item
     * @return string
     */
    public function prepareMerchantReference(TypeSourceItemInterface $item)
    {
        return sprintf('%s:%s', $item->getId(), $item->getSku());
    }

    /**
     * Prepare QliroOne order item's price
     *
     * @param \Qliro\QliroOne\Api\Product\TypeSourceItemInterface $item
     * @param bool $taxIncluded
     * @return float
     */
    public function preparePrice(TypeSourceItemInterface $item, $taxIncluded = true)
    {
        return $taxIncluded ? $item->getPriceInclTax() : $item->getPriceExclTax();
    }

    /**
     * Prepare QliroOne order item's quantity
     *
     * @param \Qliro\QliroOne\Api\Product\TypeSourceItemInterface $item
     * @return int
     */
    public function prepareQuantity(TypeSourceItemInterface $item)
    {
        return $item->getQty();
    }

    /**
     * Prepare QliroOne order item's description
     *
     * @param \Qliro\QliroOne\Api\Product\TypeSourceItemInterface $item
     * @return string
     */
    public function prepareDescription(TypeSourceItemInterface $item)
    {
        return $item->getName();
    }

    /**
     * Prepare QliroOne order item's metadata
     *
     * @param \Qliro\QliroOne\Api\Product\TypeSourceItemInterface $item
     * @return array|null
     */
    public function prepareMetaData(TypeSourceItemInterface $item)
    {
        $meta = null;
        if ($item->getSubscription()) {
            
            $meta = [
                'Subscription' => [
                    'Enabled' => true
                ]
            ];
        }
        $product = $item->getProduct();
        if ($this->config->isIngridEnabled($product->getStoreId())) {
            if($meta == null) {
                $meta = [];
            }
            $meta['Ingrid'] = [
                'Weight' => intval($product->getWeight() * 1000),
                'Sku' => $product->getSku(),
                'Attributes' => [],
                'Dimensions' => [//TODO: Create dimensions attributes
                    'Height' => 0,
                    'Length' => 0,
                    'Width' => 0
                ],
                'OutOfStock' => !$product->getExtensionAttributes()->getStockItem()->getIsInStock(),
                'Discount' => $item->getParent() ? intval($item->getParent()->getItem()->getDiscountAmount() * 100) : intval($item->getItem()->getDiscountAmount() * 100)
            ];
            return $meta;
            
        }
        return $meta;
    }
}
