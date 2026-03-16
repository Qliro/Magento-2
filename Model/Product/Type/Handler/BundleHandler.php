<?php
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
     * Get a reference to source item out of QliroOne order item, or null if not applicable
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderItemInterface $qliroOrderItem
     * @param \Qliro\QliroOne\Api\Product\TypeSourceProviderInterface $typeSourceProvider
     * @return \Qliro\QliroOne\Api\Product\TypeSourceItemInterface|null
     */
    public function getItem(array $qliroOrderItem, TypeSourceProviderInterface $typeSourceProvider)
    {
        $type = $qliroOrderItem['Type'] ?? null;
        if ($type !== 'Product' && $type !== 'Bundle') {
            return null;
        }

        return $typeSourceProvider->getSourceItemByMerchantReference($qliroOrderItem['Metadata'] ?? []);
    }

    /**
     * Prepare price depending on bundle dynamic pricing setting
     *
     * @param TypeSourceItemInterface $item
     * @param boolean $taxIncluded
     * @return void
     */
    public function preparePrice(TypeSourceItemInterface $item, $taxIncluded = true)
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
