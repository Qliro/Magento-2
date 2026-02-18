<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Product\Type\Handler;

use Qliro\QliroOne\Api\Product\TypeSourceItemInterface;

/**
 * Configurable product type handler class
 */
class ConfigurableHandler extends DefaultHandler
{
    /**
     * @inheritDoc
     */
    public function preparePrice(TypeSourceItemInterface $item, bool $taxIncluded = true): float
    {
        $parent = $item->getParent();

        if ($parent === null) {
            return parent::preparePrice($item, $taxIncluded);
        }

        return (float) ($taxIncluded ? $parent->getPriceInclTax() : $parent->getPriceExclTax());
    }

    /**
     * @inheritDoc
     */
    public function prepareQuantity(TypeSourceItemInterface $item): float
    {
        $parent = $item->getParent();

        if ($parent === null) {
            return parent::prepareQuantity($item);
        }

        return (float) $parent->getQty();
    }

    /**
     * @inheritDoc
     */
    public function prepareDescription(TypeSourceItemInterface $item): string
    {
        return (string) $item->getName();
    }
}
