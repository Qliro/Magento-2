<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Product\Type;

use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Item;
use Qliro\QliroOne\Api\Product\TypeSourceItemInterface;
use Qliro\QliroOne\Api\Product\TypeSourceProviderInterface;

/**
 * Item product type pool class
 */
class TypePoolHandler
{
    /**
     * Class constructor
     *
     * @param \Qliro\QliroOne\Model\Product\Type\TypeResolver $typeResolver
     * @param \Qliro\QliroOne\Api\Product\TypeHandlerInterface[] $pool
     */
    public function __construct(
        private readonly TypeResolver $typeResolver,
        private readonly array $pool = []
    ) {
    }

    /**
     * Resolve a QliroOne order item out of given source item
     *
     * @param \Qliro\QliroOne\Api\Product\TypeSourceItemInterface $sourceItem
     * @param \Qliro\QliroOne\Api\Product\TypeSourceProviderInterface $typeSourceProvider
     * @return array|null
     */
    public function resolveQliroOrderItem(
        TypeSourceItemInterface $sourceItem,
        TypeSourceProviderInterface $typeSourceProvider
    ) {
        $typeHash = [$sourceItem->getProduct()->getTypeId()];

        if ($parentItem = $sourceItem->getParent()) {
            $typeHash[] = $parentItem->getProduct()->getTypeId();
        }

        $handler = $this->resolveHandler(implode(':', $typeHash));

        if ($handler) {
            return $handler->getQliroOrderItem($sourceItem);
        }

        return null;
    }

    /**
     * Resolve a source item out of given QliroOne order item
     *
     * @param array $qliroOrderItem
     * @param \Qliro\QliroOne\Api\Product\TypeSourceProviderInterface $typeSourceProvider
     * @return \Qliro\QliroOne\Api\Product\TypeSourceItemInterface|null
     */
    public function resolveQuoteItem(
        array $qliroOrderItem,
        TypeSourceProviderInterface $typeSourceProvider
    ) {
        $handler = $this->resolveHandler($this->typeResolver->resolve($qliroOrderItem, $typeSourceProvider));

        if ($handler) {
            return $handler->getItem($qliroOrderItem, $typeSourceProvider)->getItem();
        }

        return null;
    }

    /**
     * Resolve handler class from a type
     *
     * @param string $type
     * @return \Qliro\QliroOne\Api\Product\TypeHandlerInterface|null
     */
    private function resolveHandler($type)
    {
        return $this->pool[$type] ?? null;
    }
}
