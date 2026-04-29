<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Product;

use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;

/**
 * Product Type Handler interface
 */
interface TypeHandlerInterface
{
    /**
     * Retrieve a QliroOne order item corresponding to the provided source item, or null if not applicable.
     *
     * @param TypeSourceItemInterface $item
     * @return QliroOrderItemInterface|null
     */
    public function getQliroOrderItem(TypeSourceItemInterface $item): ?QliroOrderItemInterface;

    /**
     * Retrieves the corresponding TypeSourceItemInterface object based on the given QliroOrderItemInterface and TypeSourceProviderInterface.
     *
     * @param QliroOrderItemInterface $qliroOrderItem The Qliro order item to process.
     * @param TypeSourceProviderInterface $typeSourceProvider The provider used to determine the type source.
     * @return TypeSourceItemInterface|null The corresponding type source item, or null if none is found.
     */
    public function getItem(
        QliroOrderItemInterface $qliroOrderItem,
        TypeSourceProviderInterface $typeSourceProvider
    ): ?TypeSourceItemInterface;

    /**
     * Generates a merchant reference based on the provided TypeSourceItemInterface.
     *
     * @param TypeSourceItemInterface $item The source item used to generate the reference.
     * @return string The generated merchant reference.
     */
    public function prepareMerchantReference(TypeSourceItemInterface $item): string;

    /**
     * Prepares and calculates the price of the given item, optionally including tax.
     *
     * @param TypeSourceItemInterface $item The item for which the price will be prepared.
     * @param bool $taxIncluded Indicates whether the tax should be included in the calculated price. Defaults to true.
     * @return float The calculated price of the item.
     */
    public function preparePrice(TypeSourceItemInterface $item, bool $taxIncluded = true): float;

    /**
     * Prepares and calculates the quantity for the given TypeSourceItemInterface instance.
     *
     * @param TypeSourceItemInterface $item The type source item to process for quantity calculation.
     * @return float The calculated quantity for the given item.
     */
    public function prepareQuantity(TypeSourceItemInterface $item): float;

    /**
     * Prepares a description string based on the given TypeSourceItemInterface object.
     *
     * @param TypeSourceItemInterface $item The item for which the description will be prepared.
     * @return string The prepared description as a string.
     */
    public function prepareDescription(TypeSourceItemInterface $item): string;

    /**
     * Prepares and returns metadata information based on the provided TypeSourceItemInterface object.
     *
     * @param TypeSourceItemInterface $item The source item used to generate metadata.
     * @return array An array containing the prepared metadata information.
     */
    public function prepareMetaData(TypeSourceItemInterface $item): array;
}
