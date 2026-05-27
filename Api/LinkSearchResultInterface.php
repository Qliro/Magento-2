<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api;

use Magento\Framework\Api\SearchResultsInterface;
use Qliro\QliroOne\Api\Data\LinkInterface;

/**
 * Link search results interface
 *
 * @api
 */
interface LinkSearchResultInterface extends SearchResultsInterface
{
    /**
     * Get link items
     *
     * @return LinkInterface[]
     */
    public function getItems();

    /**
     * Set link items
     *
     * @param LinkInterface[] $items
     * @return $this
     */
    public function setItems(array $items);
}
