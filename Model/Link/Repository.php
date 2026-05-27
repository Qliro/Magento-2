<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Link;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Exception\NoSuchEntityException;
use Qliro\QliroOne\Api\Data\LinkInterface;
use Qliro\QliroOne\Api\Data\LinkInterfaceFactory;
use Qliro\QliroOne\Api\LinkRepositoryInterface;
use Qliro\QliroOne\Api\LinkSearchResultInterface;
use Qliro\QliroOne\Model\ResourceModel\Link as LinkResourceModel;
use Qliro\QliroOne\Model\Link;
use Qliro\QliroOne\Model\ResourceModel\Link\Collection;
use Qliro\QliroOne\Api\LinkSearchResultInterfaceFactory;
use Qliro\QliroOne\Model\ResourceModel\Link\CollectionFactory;

/**
 * Link repository class
 *
 * @api
 */
class Repository implements LinkRepositoryInterface
{
    /**
     * Class constructor
     *
     * @param \Qliro\QliroOne\Model\ResourceModel\Link $linkResourceModel
     * @param \Qliro\QliroOne\Api\Data\LinkInterfaceFactory $linkFactory
     * @param \Qliro\QliroOne\Api\LinkSearchResultInterfaceFactory $searchResultFactory
     * @param \Qliro\QliroOne\Model\ResourceModel\Link\CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly LinkResourceModel $linkResourceModel,
        private readonly LinkInterfaceFactory $linkFactory,
        private readonly LinkSearchResultInterfaceFactory $searchResultFactory,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * Save a link
     *
     * @param LinkInterface $link
     * @return LinkInterface
     * @throws \Magento\Framework\Exception\AlreadyExistsException
     */
    public function save(LinkInterface $link): LinkInterface
    {
        $this->linkResourceModel->save($link);

        return $link;
    }

    /**
     * @inheritdoc
     */
    public function get(int $id, bool $onlyActive = true): LinkInterface
    {
        return $this->getByField($id, null, $onlyActive);
    }

    /**
     * @inheritdoc
     */
    public function getByQuoteId(int|string $quoteId, bool $onlyActive = true): LinkInterface
    {
        return $this->getByField($quoteId, Link::FIELD_QUOTE_ID, $onlyActive);
    }

    /**
     * @inheritdoc
     */
    public function getByOrderId(int|string $orderId, bool $onlyActive = true): LinkInterface
    {
        return $this->getByField($orderId, Link::FIELD_ORDER_ID, $onlyActive);
    }

    /**
     * @inheritdoc
     */
    public function getByQliroOrderId(mixed $qliroOrderId, bool $onlyActive = true): LinkInterface
    {
        return $this->getByField($qliroOrderId, Link::FIELD_QLIRO_ORDER_ID, $onlyActive);
    }

    /**
     * @inheritdoc
     */
    public function getByReference(string $reference, bool $onlyActive = true): LinkInterface
    {
        return $this->getByField($reference, Link::FIELD_REFERENCE, $onlyActive);
    }

    /**
     * @inheirtDoc
     */
    public function delete(LinkInterface $link): void
    {
        $this->linkResourceModel->delete($link);
    }

    /**
     * @inheirtDoc
     */
    public function getList(SearchCriteriaInterface $searchCriteria): LinkSearchResultInterface
    {
        /** @var \Qliro\QliroOne\Model\ResourceModel\Link\Collection $collection */
        $collection = $this->collectionFactory->create();

        $this->addFiltersToCollection($searchCriteria, $collection);
        $this->addSortOrdersToCollection($searchCriteria, $collection);
        $this->addPaginationToCollection($searchCriteria, $collection);

        $collection->load();

        return $this->buildSearchResult($searchCriteria, $collection);
    }

    /**
     * @param SearchCriteriaInterface $searchCriteria
     * @param Collection $collection
     * @return void
     */
    private function addFiltersToCollection(SearchCriteriaInterface $searchCriteria, Collection $collection): void
    {
        foreach ($searchCriteria->getFilterGroups() as $filterGroup) {
            $fields = $conditions = [];
            foreach ($filterGroup->getFilters() as $filter) {
                $fields[] = $filter->getField();
                $conditions[] = [$filter->getConditionType() => $filter->getValue()];
            }
            $collection->addFieldToFilter($fields, $conditions);
        }
    }

    /**
     * @param SearchCriteriaInterface $searchCriteria
     * @param Collection $collection
     * @return void
     */
    private function addSortOrdersToCollection(SearchCriteriaInterface $searchCriteria, Collection $collection): void
    {
        foreach ((array) $searchCriteria->getSortOrders() as $sortOrder) {
            $direction = $sortOrder->getDirection() == SortOrder::SORT_ASC ? 'asc' : 'desc';
            $collection->addOrder($sortOrder->getField(), $direction);
        }
    }

    /**
     * @param SearchCriteriaInterface $searchCriteria
     * @param Collection $collection
     * @return void
     */
    private function addPaginationToCollection(SearchCriteriaInterface $searchCriteria, Collection $collection): void
    {
        $collection->setPageSize($searchCriteria->getPageSize());
        $collection->setCurPage($searchCriteria->getCurrentPage());
    }

    /**
     * @param SearchCriteriaInterface $searchCriteria
     * @param Collection $collection
     * @return LinkSearchResultInterface
     */
    private function buildSearchResult(SearchCriteriaInterface $searchCriteria, Collection $collection): LinkSearchResultInterface
    {
        /** @var LinkSearchResultInterface $searchResults */
        $searchResults = $this->searchResultFactory->create();

        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }

    /**
     * Get a link by a specified field
     *
     * @param string|int $value
     * @param string|null $field
     * @param bool $onlyActive
     * @return LinkInterface
     * @throws NoSuchEntityException
     */
    private function getByField(mixed $value, ?string $field, bool $onlyActive = true): LinkInterface
    {
        /** @var Link $link */
        if ($onlyActive) {
            $collection = $this->collectionFactory->create()
                ->addFieldToFilter($field, $value)
                ->addFieldToFilter(Link::FIELD_IS_ACTIVE, 1);
            $link = $collection->getFirstItem();
        } else {
            $link = $this->linkFactory->create();
            $this->linkResourceModel->load($link, $value, $field);
        }

        if (!$link->getId()) {
            throw new NoSuchEntityException(__('Cannot find a link with %1 = "%2"', $field, $value));
        }

        return $link;
    }

    /**
     * @inheritDoc
     */
    public function lock(int $quoteId): LinkInterface
    {
        $link = $this->getByQuoteId($quoteId);
        if (!$link->getIsLocked()) {
            $link->setIsLocked(true);
            $this->save($link);
        }

        return $link;
    }

    /**
     * @inheritDoc
     */
    public function unlock(int $quoteId): LinkInterface
    {
        $link = $this->getByQuoteId($quoteId);
        if ($link->getIsLocked()) {
            $link->setIsLocked(false);
            $this->save($link);
        }

        return $link;
    }
}
