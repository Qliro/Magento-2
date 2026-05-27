<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\OrderManagementStatus;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Exception\NoSuchEntityException;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterface;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterfaceFactory;
use Qliro\QliroOne\Api\OrderManagementStatusRepositoryInterface;
use Qliro\QliroOne\Api\OrderManagementStatusSearchResultInterface;
use Qliro\QliroOne\Model\ResourceModel\OrderManagementStatus as OrderManagementStatusResourceModel;
use Qliro\QliroOne\Model\OrderManagementStatus;
use Qliro\QliroOne\Model\ResourceModel\OrderManagementStatus\Collection;
use Qliro\QliroOne\Api\OrderManagementStatusSearchResultInterfaceFactory;
use Qliro\QliroOne\Model\ResourceModel\OrderManagementStatus\CollectionFactory;

/**
 * OrderManagementStatus repository class
 *
 * @api
 */
class Repository implements OrderManagementStatusRepositoryInterface
{
    /**
     * Class constructor
     *
     * @param OrderManagementStatusResourceModel $OrderManagementStatusResourceModel
     * @param OrderManagementStatusInterfaceFactory $OrderManagementStatusFactory
     * @param OrderManagementStatusSearchResultInterfaceFactory $searchResultFactory
     * @param CollectionFactory $collectionFactory
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SortOrderBuilder $sortOrderBuilder
     */
    public function __construct(
        private readonly OrderManagementStatusResourceModel $OrderManagementStatusResourceModel,
        private readonly OrderManagementStatusInterfaceFactory $OrderManagementStatusFactory,
        private readonly OrderManagementStatusSearchResultInterfaceFactory $searchResultFactory,
        private readonly CollectionFactory $collectionFactory,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly SortOrderBuilder $sortOrderBuilder
    ) {
    }

    /**
     * @inheirtDoc
     */
    public function save(OrderManagementStatusInterface $orderManagementStatus): OrderManagementStatusInterface
    {
        $this->OrderManagementStatusResourceModel->save($orderManagementStatus);

        return $orderManagementStatus;
    }

    /**
     * @inheritdoc
     */
    public function get(int $id): OrderManagementStatusInterface
    {
        return $this->getByField($id);
    }

    /**
     * @inHeirtDoc
     */
    public function getParent(mixed $id): ?OrderManagementStatusInterface
    {
        /** @var \Magento\Framework\Api\SortOrder $sortOrder */
        $sortOrder = $this->sortOrderBuilder->setField('date')->setDirection(SortOrder::SORT_DESC)->create();

        /** @var \Magento\Framework\Api\SearchCriteria $search */
        $search = $this->searchCriteriaBuilder
            ->addFilter('transaction_id',$id, 'eq')
            ->addFilter('record_type', 'null', 'neq')
            ->addSortOrder($sortOrder)
            ->create();

        $searchResult = $this->getList($search);
        foreach ($searchResult->getItems() as $parent) {
            return $parent;
        }

        return null;
    }

    /**
     * @inHeirtDoc
     */
    public function getPrevious(mixed $id): ?OrderManagementStatusInterface
    {
        /** @var \Magento\Framework\Api\SortOrder $sortOrder */
        $sortOrder = $this->sortOrderBuilder->setField('date')->setDirection(SortOrder::SORT_DESC)->create();

        /** @var \Magento\Framework\Api\SearchCriteria $search */
        $search = $this->searchCriteriaBuilder
            ->addFilter('transaction_id',$id, 'eq')
            ->addFilter('notification_status',OrderManagementStatusInterface::NOTIFICATION_STATUS_DONE, 'eq')
            ->addSortOrder($sortOrder)
            ->create();

        $searchResult = $this->getList($search);
        foreach ($searchResult->getItems() as $previous) {
            return $previous;
        }

        return null;
    }

    /**
     * @inheritdoc
     */
    public function delete(OrderManagementStatusInterface $OrderManagementStatus): void
    {
        $this->OrderManagementStatusResourceModel->delete($OrderManagementStatus);
    }

    /**
     * @inheritdoc
     */
    public function getList(SearchCriteriaInterface $searchCriteria): OrderManagementStatusSearchResultInterface
    {
        /** @var Collection $collection */
        $collection = $this->collectionFactory->create();

        $this->addFiltersToCollection($searchCriteria, $collection);
        $this->addSortOrdersToCollection($searchCriteria, $collection);
        $this->addPaginationToCollection($searchCriteria, $collection);

        $collection->load();

        return $this->buildSearchResult($searchCriteria, $collection);
    }

    /**
     * Add filters to the collection
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @param Collection $collection
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
     * Add sort order to the collection
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @param Collection $collection
     */
    private function addSortOrdersToCollection(SearchCriteriaInterface $searchCriteria, Collection $collection): void
    {
        foreach ((array) $searchCriteria->getSortOrders() as $sortOrder) {
            $direction = $sortOrder->getDirection() == SortOrder::SORT_ASC ? 'asc' : 'desc';
            $collection->addOrder($sortOrder->getField(), $direction);
        }
    }

    /**
     * Add pagination to a collection
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @param Collection $collection
     */
    private function addPaginationToCollection(SearchCriteriaInterface $searchCriteria, Collection $collection): void
    {
        $collection->setPageSize($searchCriteria->getPageSize());
        $collection->setCurPage($searchCriteria->getCurrentPage());
    }

    /**
     * Build search result
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @param Collection $collection
     * @return OrderManagementStatusSearchResultInterface
     */
    private function buildSearchResult(SearchCriteriaInterface $searchCriteria, Collection $collection): OrderManagementStatusSearchResultInterface
    {
        /** @var OrderManagementStatusSearchResultInterface $searchResults */
        $searchResults = $this->searchResultFactory->create();

        $searchResults->setSearchCriteria($searchCriteria);
        $searchResults->setItems($collection->getItems());
        $searchResults->setTotalCount($collection->getSize());

        return $searchResults;
    }

    /**
     * Get an OrderManagementStatus by a specified field
     *
     * @param string|int $value
     * @return OrderManagementStatus
     * @throws NoSuchEntityException
     */
    private function getByField(mixed $value): OrderManagementStatusInterface
    {
        /** @var OrderManagementStatus $OrderManagementStatus */
        $OrderManagementStatus = $this->OrderManagementStatusFactory->create();
        $this->OrderManagementStatusResourceModel->load($OrderManagementStatus, $value, null);

        if (!$OrderManagementStatus->getId()) {
            throw new NoSuchEntityException(__('Cannot find a OrderManagementStatus with %1 = "%2"', null, $value));
        }

        return $OrderManagementStatus;
    }
}
