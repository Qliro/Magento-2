<?php
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Product\Type\Handler;

use Qliro\QliroOne\Api\Product\TypeSourceItemInterface;
use Qliro\QliroOne\Api\Product\TypeSourceProviderInterface;
use Magento\Bundle\Model\Product\Type as BundleType;

/**
 * Bundle product type handler class
 */
class BundleHandler extends DefaultHandler
{
    /**
     * @inHeirtDoc
     */
    public function getItem(array $qliroOrderItem, TypeSourceProviderInterface $typeSourceProvider): ?TypeSourceItemInterface
    {
        $type = $qliroOrderItem['Type'] ?? null;
        if ($type !== 'Product' && $type !== 'Bundle') {
            return null;
        }

        return $typeSourceProvider->getSourceItemByMerchantReference($qliroOrderItem['Metadata'] ?? []);
    }

    /**
     * @inHeirtDoc
     */
    public function preparePrice(TypeSourceItemInterface $item, bool $taxIncluded = true): float
    {
        if ($item->getType() !== BundleType::TYPE_CODE) {
            return parent::preparePrice($item, $taxIncluded);
        }

        if ((int)$item->getProduct()->getPriceType() === 0) {
            return 0;
        }

        return parent::preparePrice($item, $taxIncluded);
    }
}
