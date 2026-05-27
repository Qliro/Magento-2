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
     * @inHeirtDoc
     */
    public function preparePrice(TypeSourceItemInterface $item, bool $taxIncluded = true): float
    {
        $parent = $item->getParent();

        return (float)($taxIncluded ? $parent->getPriceInclTax() : $parent->getPriceExclTax());
    }

    /**
     * @inHeirtDoc
     */
    public function prepareQuantity(TypeSourceItemInterface $item): int
    {
        $parent = $item->getParent();

        return (int)$parent->getQty();
    }

    /**
     * @inHeirtDoc
     */
    public function prepareDescription(TypeSourceItemInterface $item): string
    {
        return (string)$item->getName();
    }
}
