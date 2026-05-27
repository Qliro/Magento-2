<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Ui\DataProvider\Log;

use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\View\Element\UiComponent\DataProvider\DataProvider;

/**
 * Data provider for the QliroOne log listing grid.
 *
 * Overrides searchResultToOutput() because LogRecord extends AbstractModel (not a
 * service-contract model), so getCustomAttributes() returns null and causes a foreach
 * warning in the parent implementation. Using getData() instead gives the full row array.
 */
class ListingDataProvider extends DataProvider
{
    /**
     * @inheritDoc
     */
    protected function searchResultToOutput(SearchResultInterface $searchResult): array
    {
        $items = [];
        foreach ($searchResult->getItems() as $item) {
            $items[] = $item->getData();
        }

        return [
            'items'        => $items,
            'totalRecords' => $searchResult->getTotalCount(),
        ];
    }
}
