<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\ResourceModel\LogRecord\Grid;

use Magento\Framework\Api\Search\AggregationInterface;
use Magento\Framework\Api\Search\SearchResultInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Qliro\QliroOne\Model\ResourceModel\LogRecord\Collection as LogRecordCollection;

/**
 * Grid collection for the qliroone_log admin listing.
 * Wraps the standard LogRecord collection and implements SearchResultInterface
 * so that the Magento UI grid data provider can drive filters, sorting, and pagination.
 */
class Collection extends LogRecordCollection implements SearchResultInterface
{
    /**
     * @var AggregationInterface
     */
    private AggregationInterface $aggregations;

    /**
     * @var SearchCriteriaInterface|null
     */
    private ?SearchCriteriaInterface $searchCriteria = null;

    /** @inheritDoc */
    public function getAggregations(): AggregationInterface
    {
        return $this->aggregations;
    }

    /** @inheritDoc */
    public function setAggregations($aggregations): static
    {
        $this->aggregations = $aggregations;
        return $this;
    }

    /** @inheritDoc */
    public function getSearchCriteria(): ?SearchCriteriaInterface
    {
        return $this->searchCriteria;
    }

    /** @inheritDoc */
    public function setSearchCriteria(SearchCriteriaInterface $searchCriteria): static
    {
        $this->searchCriteria = $searchCriteria;
        return $this;
    }

    /** @inheritDoc */
    public function getTotalCount(): int
    {
        return $this->getSize();
    }

    /** @inheritDoc */
    public function setTotalCount($totalCount): static
    {
        return $this;
    }

    /** @inheritDoc */
    public function setItems(?array $items = null): static
    {
        return $this;
    }
}
