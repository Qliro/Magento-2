<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Product;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\Quote\Item;
use Magento\Store\Model\StoreManagerInterface as StoreManager;
use Magento\Tax\Model\Calculation;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterface;

/**
 * Class VatRate
 */
class VatRate
{
    /**
     * Class constructor
     *
     * @param StoreManager            $storeManager
     * @param Calculation             $taxCalculation
     */
    public function __construct(

        private readonly StoreManager $storeManager,
        private readonly Calculation  $taxCalculation
    ) {}

    /**
     * @param Item|TypeSourceItemInterface $item
     * @return float
     * @throws LocalizedException
     * @throws NoSuchEntityException
     */
    public function getVatRateForProduct(TypeSourceItemInterface|Item $item): float {
        $product = $item->getProduct();
        $productTaxClassId = $product->getTaxClassId();
        $store = $this->storeManager->getStore();

        $rateRequest = $this->taxCalculation->getRateRequest(
            false,
            false,
            false,
            $store
        );
        $rateRequest->setProductClassId((int)$product->getTaxClassId());

        return (float)$this->taxCalculation->getRate($rateRequest->setProductClassId($productTaxClassId));
    }
}
