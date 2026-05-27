<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Product;

use Magento\Catalog\Api\ProductRepositoryInterface;

/**
 * Product pool class for faster access to quote item products
 */
class ProductPool
{
    /**
     * @var \Magento\Catalog\Model\Product[]
     */
    private array $products = [];

    /**
     * Class constructor
     *
     * @param \Magento\Catalog\Api\ProductRepositoryInterface $productRepository
     */
    public function __construct(
        private readonly ProductRepositoryInterface $productRepository
    ) {
    }

    /**
     * Get a product by SKU
     *
     * @param string $sku
     * @param int|null $storeId
     * @return \Magento\Catalog\Model\Product
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getProduct(string $sku, int|string|null $storeId = null): \Magento\Catalog\Api\Data\ProductInterface
    {
        if (!isset($this->products[$sku])) {
            $this->products[$sku] = $this->productRepository->get($sku, false, $storeId);
        }

        return $this->products[$sku];
    }
}
