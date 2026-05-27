<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api;

use Magento\Framework\Api\SearchResultsInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterface;

/**
 * OrderManagementStatus search results interface
 *
 * @api
 */
interface OrderManagementStatusSearchResultInterface extends SearchResultsInterface
{
    /**
     * Get OrderManagementStatus items
     *
     * @return OrderManagementStatusInterface[]
     */
    public function getItems();

    /**
     * Set OrderManagementStatus items
     *
     * @param OrderManagementStatusInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
