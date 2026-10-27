<?php

declare(strict_types=1);

namespace Qliro\QliroOne\Model\Product\Type\Handler;

use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterface;
use Qliro\QliroOne\Api\Product\TypeSourceProviderInterface;
use Magento\Bundle\Model\Product\Type as BundleType;
use Magento\Quote\Model\Quote\Item\AbstractItem;

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
    public function getItem(QliroOrderItemInterface $qliroOrderItem, TypeSourceProviderInterface $typeSourceProvider)
    {
        if ($qliroOrderItem->getType() !== QliroOrderItemInterface::TYPE_PRODUCT &&
            $qliroOrderItem->getType() !== QliroOrderItemInterface::TYPE_BUNDLE) {
            return null;
        }

        return $typeSourceProvider->getSourceItemByMerchantReference($qliroOrderItem->getMetadata());
    }

    /**
     * A child of a bundle priced by its children counts its units per bundle in the cart, the line carries them
     * for all bundles
     *
     * @param TypeSourceItemInterface $item
     * @return float
     */
    public function prepareQuantity(TypeSourceItemInterface $item)
    {
        $cartItem = $item->getItem();

        if ($cartItem instanceof AbstractItem && $item->getParent()?->getItem()?->isChildrenCalculated()) {
            return (float)$cartItem->getTotalQty();
        }

        return parent::prepareQuantity($item);
    }

    /**
     * Prepare price depending on bundle dynamic pricing setting
     *
     * Read off the cart or order item, as the capture decides which lines carry the price.
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

        if ($item->getItem()?->isChildrenCalculated()) {
            return 0;
        }

        return parent::preparePrice($item, $taxIncluded);
    }
}
