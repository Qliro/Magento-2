<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

// @codingStandardsIgnoreFile
// phpcs:ignoreFile

namespace Qliro\QliroOne\Model\OrderManagementStatus;

use Magento\Framework\Api\SearchResults;
use Qliro\QliroOne\Api\OrderManagementStatusSearchResultInterface;

/**
 * OrderManagementStatus search result class
 */
class SearchResult extends SearchResults implements OrderManagementStatusSearchResultInterface
{
}
