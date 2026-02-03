<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Product\Type\Handler;

use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory as QliroOrderItemFactory;
use Qliro\QliroOne\Api\Product\TypeHandlerInterface;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterface;
use Qliro\QliroOne\Api\Product\TypeSourceProviderInterface;
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Product\VatRate;

/**
 * Default product type handler class
 */
class DefaultHandler implements TypeHandlerInterface
{
    /**
     * Class constructor
     *
     * @param QliroOrderItemFactory            $qliroOrderItemFactory
     * @param Data                             $qliroHelper
     * @param Config                           $config
     * @param VatRate                          $vatRate
     */
    public function __construct(
        private readonly QliroOrderItemFactory $qliroOrderItemFactory,
        private readonly Data                  $qliroHelper,
        private readonly Config                $config,
        private readonly VatRate               $vatRate
    ) {
    }

    /**
     * @inheirtDoc
     */
    public function getQliroOrderItem(TypeSourceItemInterface $item)
    {
        $pricePerItemIncVat = $this->preparePrice($item);
        $pricePerItemExVat = $this->preparePrice($item, false);

        $qliroOrderItem = $this->qliroOrderItemFactory->create();
        $qliroOrderItem->setMerchantReference($this->prepareMerchantReference($item));
        $qliroOrderItem->setType(QliroOrderItemInterface::TYPE_PRODUCT);
        $qliroOrderItem->setQuantity($this->prepareQuantity($item));
        $qliroOrderItem->setPricePerItemIncVat((float)$this->qliroHelper->formatPrice($pricePerItemIncVat));
        $qliroOrderItem->setPricePerItemExVat((float)$this->qliroHelper->formatPrice($pricePerItemExVat));
        $qliroOrderItem->setVatRate($this->vatRate->getVatRateForProduct($item)); //@Todo make value dynamic
        $qliroOrderItem->setDescription($this->prepareDescription($item));
        $qliroOrderItem->setMetaData($this->prepareMetaData($item));

        return $qliroOrderItem;
    }

    /**
     * @inheirtDoc
     */
    public function getItem(
        QliroOrderItemInterface $qliroOrderItem,
        TypeSourceProviderInterface $typeSourceProvider
    ) {
        if ($qliroOrderItem->getType() !== QliroOrderItemInterface::TYPE_PRODUCT) {
            return null;
        }

        return $typeSourceProvider->getSourceItemByMerchantReference($qliroOrderItem->getMetadata());
    }

    /**
     * @inheirtDoc
     */
    public function prepareMerchantReference(TypeSourceItemInterface $item)
    {
        return sprintf('%s:%s', $item->getId(), $item->getSku());
    }

    /**
     * @inheirtDoc
     */
    public function preparePrice(TypeSourceItemInterface $item, $taxIncluded = true)
    {
        return $taxIncluded ? $item->getPriceInclTax() : $item->getPriceExclTax();
    }

    /**
     * @inheirtDoc
     */
    public function prepareQuantity(TypeSourceItemInterface $item)
    {
        return $item->getQty();
    }

    /**
     * @inheirtDoc
     */
    public function prepareDescription(TypeSourceItemInterface $item)
    {
        return $item->getName();
    }

    /**
     * @inheirtDoc
     */
    public function prepareMetaData(TypeSourceItemInterface $item)
    {
        $meta = [
            'qliro' => 'checkout'
        ];
        if ($item->getSubscription()) {
            
            $meta = [
                'Subscription' => [
                    'Enabled' => true
                ]
            ];
        }

        $meta['quoteItems'] = [
            $this->prepareMerchantReference($item) => $this->prepareMerchantReference($item),
        ];

        $product = $item->getProduct();
        if ($this->config->isIngridEnabled($product->getStoreId())) {
            //if($meta == null) {
            //    $meta = [];
            //}
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
                'Discount' => $item->getParent() ?
                    intval($item->getParent()->getItem()->getDiscountAmount() * 100) :
                    intval($item->getItem()->getDiscountAmount() * 100)
            ];
            return $meta;
            
        }
        return $meta;
    }
}
