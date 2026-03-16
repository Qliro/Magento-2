<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Product\Type\Handler;

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

        return [
            'MerchantReference' => (string)$item->getSku(),
            'Type' => 'Product',
            'Quantity' => (float)$this->prepareQuantity($item),
            'PricePerItemIncVat' => (float)$this->qliroHelper->formatPrice($pricePerItemIncVat),
            'PricePerItemExVat' => (float)$this->qliroHelper->formatPrice($pricePerItemExVat),
            'VatRate' => (float)$this->vatRate->getVatRateForProduct($item),
            'Description' => (string)$this->prepareDescription($item),
            'Metadata' => (array)$this->prepareMetaData($item),
        ];
    }

    /**
     * @inheirtDoc
     */
    public function getItem(
        array $qliroOrderItem,
        TypeSourceProviderInterface $typeSourceProvider
    ) {
        if (($qliroOrderItem['Type'] ?? null) !== 'Product') {
            return null;
        }

        return $typeSourceProvider->getSourceItemByMerchantReference($qliroOrderItem['Metadata'] ?? []);
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
