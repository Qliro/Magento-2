<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Product\Type\Handler;

use Magento\Catalog\Model\Product;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory as QliroOrderItemFactory;
use Qliro\QliroOne\Api\Product\TypeHandlerInterface;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterface;
use Qliro\QliroOne\Api\Product\TypeSourceProviderInterface;
use Qliro\QliroOne\Helper\Data;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Product\VatRate;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * Default product type handler class
 */
class DefaultHandler implements TypeHandlerInterface
{
    /**
     * @param QliroOrderItemFactory $qliroOrderItemFactory
     * @param Data $qliroHelper
     * @param Config $config
     * @param VatRate $vatRate
     * @param LogManager $logger
     */
    public function __construct(
        private readonly QliroOrderItemFactory $qliroOrderItemFactory,
        private readonly Data                  $qliroHelper,
        private readonly Config                $config,
        private readonly VatRate               $vatRate,
        private readonly LogManager            $logger
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getQliroOrderItem(TypeSourceItemInterface $item): QliroOrderItemInterface
    {
        $pricePerItemIncVat = $this->preparePrice($item);
        $pricePerItemExVat  = $this->preparePrice($item, false);

        $qliroOrderItem = $this->qliroOrderItemFactory->create();
        $qliroOrderItem->setMerchantReference($item->getSku());
        $qliroOrderItem->setType(QliroOrderItemInterface::TYPE_PRODUCT);
        $qliroOrderItem->setQuantity($this->prepareQuantity($item));
        $qliroOrderItem->setPricePerItemIncVat((float) $this->qliroHelper->formatPrice($pricePerItemIncVat));
        $qliroOrderItem->setPricePerItemExVat((float) $this->qliroHelper->formatPrice($pricePerItemExVat));
        $qliroOrderItem->setVatRate($this->vatRate->getVatRateForProduct($item));
        $qliroOrderItem->setDescription($this->prepareDescription($item));
        $qliroOrderItem->setMetaData($this->prepareMetaData($item));

        return $qliroOrderItem;
    }

    /**
     * @inheritDoc
     */
    public function getItem(
        QliroOrderItemInterface $qliroOrderItem,
        TypeSourceProviderInterface $typeSourceProvider
    ): ?TypeSourceItemInterface {
        if ($qliroOrderItem->getType() !== QliroOrderItemInterface::TYPE_PRODUCT) {
            return null;
        }

        return $typeSourceProvider->getSourceItemByMerchantReference($qliroOrderItem->getMetadata());
    }

    /**
     * @inheritDoc
     */
    public function prepareMerchantReference(TypeSourceItemInterface $item): string
    {
        return (string) $item->getSku();
    }

    /**
     * @inheritDoc
     */
    public function preparePrice(TypeSourceItemInterface $item, bool $taxIncluded = true): float
    {
        return (float) ($taxIncluded ? $item->getPriceInclTax() : $item->getPriceExclTax());
    }

    /**
     * @inheritDoc
     */
    public function prepareQuantity(TypeSourceItemInterface $item): float
    {
        return (float) $item->getQty();
    }

    /**
     * @inheritDoc
     */
    public function prepareDescription(TypeSourceItemInterface $item): string
    {
        return (string) $item->getName();
    }

    /**
     * @inheritDoc
     */
    public function prepareMetaData(TypeSourceItemInterface $item): array
    {
        $meta = ['qliro' => 'checkout'];

        if ($item->getSubscription()) {
            $meta['Subscription'] = ['Enabled' => true];
        }

        $ref = $this->prepareMerchantReference($item);
        $meta['quoteItems'] = [$ref => $ref];

        $product = $item->getProduct();

        if ($this->config->isIngridEnabled($product->getStoreId())) {
            $meta['Ingrid'] = $this->prepareIngridMeta($item, $product);
        }

        return $meta;
    }

    /**
     * Prepares metadata for an Ingrid item based on the provided source item and product.
     *
     * @param TypeSourceItemInterface $item The source item to derive metadata for.
     * @param Product $product The product from which additional metadata is retrieved.
     * @return array An array containing Ingrid metadata including weight, SKU, attributes, dimensions, stock status, and discount.
     */
    private function prepareIngridMeta(TypeSourceItemInterface $item, Product $product): array
    {
        return [
            'Weight'     => $this->prepareWeight($product),
            'Sku'        => $product->getSku(),
            'Attributes' => $item->getItem()->getIsVirtual() ? ['ONLY_VIRTUAL'] : [],
            'Dimensions' => $this->prepareDimensions($product),
            'OutOfStock' => !$this->isInStock($product),
            'Discount'   => $this->prepareDiscount($item),
        ];
    }

    /**
     * Determines whether the given product is in stock.
     *
     * @param Product $product The product to check stock availability for.
     * @return bool True if the product is in stock, false otherwise.
     */
    private function isInStock(Product $product): bool
    {
        try {
            $stockItem = $product->getExtensionAttributes()?->getStockItem();
            return $stockItem ? (bool) $stockItem->getIsInStock() : false;
        } catch (\Throwable $e) {
            $this->logger->warning('QliroOne: Could not determine stock status', [
                'sku'   => $product->getSku(),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Prepares the weight of the given product in grams.
     *
     * @param Product $product The product for which the weight is to be prepared.
     * @return int The weight of the product in grams, rounded to the nearest integer.
     */
    private function prepareWeight(Product $product): int
    {
        return (int) round((float) $product->getWeight() * 1000);
    }

    /**
     * Prepares the dimensions of the given product.
     *
     * @param Product $product The product for which dimensions are being prepared.
     * @return array An associative array containing the dimensions 'Height', 'Length', and 'Width'.
     */
    private function prepareDimensions(Product $product): array
    {
        return [
            'Height' => (int) ($product->getData('height') ?? 0),
            'Length' => (int) ($product->getData('length') ?? 0),
            'Width'  => (int) ($product->getData('width')  ?? 0),
        ];
    }

    /**
     * Calculates the discount for a given item in cents.
     *
     * @param TypeSourceItemInterface $item The item for which the discount is being prepared.
     * @return int The discount amount in cents.
     */
    private function prepareDiscount(TypeSourceItemInterface $item): int
    {
        $discountAmount = $item->getParent()
            ? $item->getParent()->getItem()->getDiscountAmount()
            : $item->getItem()->getDiscountAmount();

        return (int) round((float) $discountAmount * 100);
    }
}
