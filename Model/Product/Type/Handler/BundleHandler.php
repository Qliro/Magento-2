<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Product\Type\Handler;

use Magento\Bundle\Model\Product\Type as BundleType;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterface;
use Qliro\QliroOne\Api\Product\TypeSourceProviderInterface;

/**
 * Bundle product type handler class
 */
class BundleHandler extends DefaultHandler
{
    private const BUNDLE_PRICE_TYPE_DYNAMIC = 0;

    /**
     * @inheritDoc
     */
    public function getItem(
        QliroOrderItemInterface $qliroOrderItem,
        TypeSourceProviderInterface $typeSourceProvider
    ): ?TypeSourceItemInterface
    {
        $type = $qliroOrderItem->getType();
        if ($type !== QliroOrderItemInterface::TYPE_PRODUCT &&
            $type !== QliroOrderItemInterface::TYPE_BUNDLE) {
            return null;
        }

        return $typeSourceProvider->getSourceItemByMerchantReference($qliroOrderItem->getMetadata());
    }

    /**
     * Prepare price depending on bundle dynamic pricing setting.
     * Returns 0.0 for dynamically-priced bundles (price is sum of children).
     *
     * @param TypeSourceItemInterface $item
     * @param bool $taxIncluded
     * @return float
     */
    public function preparePrice(TypeSourceItemInterface $item, bool $taxIncluded = true): float
    {
        if ($item->getType() !== BundleType::TYPE_CODE) {
            return parent::preparePrice($item, $taxIncluded);
        }

        if ((int) $item->getProduct()->getPriceType() === self::BUNDLE_PRICE_TYPE_DYNAMIC) {
            return 0.0;
        }

        return parent::preparePrice($item, $taxIncluded);
    }
}
