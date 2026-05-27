<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Product;

/**
 * Product Type Source Provider interface
 *
 * @api
 */
interface TypeSourceProviderInterface
{
    /**
     * Get the store ID for this provider context
     *
     * @return int
     */
    public function getStoreId(): int;

    /**
     * Find a source item by its Qliro merchant reference
     *
     * @param mixed $reference
     * @return TypeSourceItemInterface|null
     */
    public function getSourceItemByMerchantReference(mixed $reference): ?TypeSourceItemInterface;

    /**
     * Get all source items provided by this context
     *
     * @return TypeSourceItemInterface[]
     */
    public function getSourceItems(): array;

    /**
     * Generate a TypeSourceItem from a raw quote or order item
     *
     * @param mixed $item
     * @param float $quantity
     * @return TypeSourceItemInterface
     */
    public function generateSourceItem(mixed $item, float $quantity): TypeSourceItemInterface;
}
