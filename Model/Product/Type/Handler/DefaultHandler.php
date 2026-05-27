<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Product\Type\Handler;

use Qliro\QliroOne\Api\Product\TypeHandlerInterface as ProductTypeHandler;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterface as ProductTypeSourceItem;
use Qliro\QliroOne\Api\Product\TypeSourceProviderInterface as ProductTypeSourceProvider;
use Qliro\QliroOne\Model\Formatter\PriceFormatter;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Product\VatRate;

/**
 * Default product type handler class
 */
class DefaultHandler implements ProductTypeHandler
{
    /**
     * Class constructor
     *
     * @param Data               $qliroHelper
     * @param Config             $config
     * @param VatRate            $vatRate
     */
    public function __construct(
        private readonly PriceFormatter $priceFormatter,
        private readonly Config  $config,
        private readonly VatRate $vatRate
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getQliroOrderItem(ProductTypeSourceItem $item): ?array
    {
        $pricePerItemIncVat = $this->preparePrice($item);
        $pricePerItemExVat = $this->preparePrice($item, false);

        return [
            'MerchantReference' => (string)$item->getSku(),
            'Type' => 'Product',
            'Quantity' => (float)$this->prepareQuantity($item),
            'PricePerItemIncVat' => (float)$this->priceFormatter->format($pricePerItemIncVat),
            'PricePerItemExVat' => (float)$this->priceFormatter->format($pricePerItemExVat),
            'VatRate' => (float)$this->vatRate->getVatRateForProduct($item),
            'Description' => (string)$this->prepareDescription($item),
            'Metadata' => (array)$this->prepareMetaData($item),
        ];
    }

    /**
     * @inheritDoc
     */
    public function getItem(array $qliroOrderItem, ProductTypeSourceProvider $typeSourceProvider): ?ProductTypeSourceItem {
        if (($qliroOrderItem['Type'] ?? null) !== 'Product') {
            return null;
        }

        return $typeSourceProvider->getSourceItemByMerchantReference($qliroOrderItem['Metadata'] ?? []);
    }

    /**
     * @inheritDoc
     */
    public function prepareMerchantReference(ProductTypeSourceItem $item): string
    {
        return sprintf('%s:%s', $item->getId(), $item->getSku());
    }

    /**
     * @inheritDoc
     */
    public function preparePrice(ProductTypeSourceItem $item, bool $taxIncluded = true): float
    {
        return (float)($taxIncluded ? $item->getPriceInclTax() : $item->getPriceExclTax());
    }

    /**
     * @inheritDoc
     */
    public function prepareQuantity(ProductTypeSourceItem $item): int
    {
        return (int)$item->getQty();
    }

    /**
     * @inheritDoc
     */
    public function prepareDescription(ProductTypeSourceItem $item): string
    {
        return (string)$item->getName();
    }

    /**
     * @inheritDoc
     */
    public function prepareMetaData(ProductTypeSourceItem $item): ?array
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
