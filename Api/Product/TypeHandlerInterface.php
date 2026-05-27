<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Product;

/**
 * Product Type Handler interface
 *
 * @api
 */
interface TypeHandlerInterface
{
    /**
     * Build a QliroOne order item array from a source item, or null if not applicable
     *
     * @param TypeSourceItemInterface $item
     * @return array|null
     */
    public function getQliroOrderItem(TypeSourceItemInterface $item): ?array;

    /**
     * Find the source item matching a QliroOne order item, or null if not applicable
     *
     * @param array $qliroOrderItem
     * @param TypeSourceProviderInterface $typeSourceProvider
     * @return TypeSourceItemInterface|null
     */
    public function getItem(array $qliroOrderItem, TypeSourceProviderInterface $typeSourceProvider): ?TypeSourceItemInterface;

    /**
     * Build the merchant reference string for a source item
     *
     * @param TypeSourceItemInterface $item
     * @return string
     */
    public function prepareMerchantReference(TypeSourceItemInterface $item): string;

    /**
     * Build the unit price for a source item
     *
     * @param TypeSourceItemInterface $item
     * @param bool $taxIncluded
     * @return float
     */
    public function preparePrice(TypeSourceItemInterface $item, bool $taxIncluded = true): float;

    /**
     * Build the quantity for a source item
     *
     * @param TypeSourceItemInterface $item
     * @return int
     */
    public function prepareQuantity(TypeSourceItemInterface $item): int;

    /**
     * Build the display description for a source item
     *
     * @param TypeSourceItemInterface $item
     * @return string
     */
    public function prepareDescription(TypeSourceItemInterface $item): string;

    /**
     * Build the metadata array for a source item, or null if none
     *
     * @param TypeSourceItemInterface $item
     * @return array|null
     */
    public function prepareMetaData(TypeSourceItemInterface $item): ?array;
}
