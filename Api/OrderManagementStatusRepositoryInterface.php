<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api;

use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\NoSuchEntityException;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterface;

/**
 * OrderManagementStatus repository interface
 *
 * @api
 */
interface OrderManagementStatusRepositoryInterface
{
    /**
     * Get a status by its ID
     *
     * @param int $id
     * @return OrderManagementStatusInterface
     * @throws NoSuchEntityException
     */
    public function get(int $id): OrderManagementStatusInterface;

    /**
     * Get the parent status by transaction ID
     *
     * @param mixed $id
     * @return OrderManagementStatusInterface|null
     */
    public function getParent(mixed $id): ?OrderManagementStatusInterface;

    /**
     * Get the most recently received status for a given transaction ID
     *
     * @param mixed $id
     * @return OrderManagementStatusInterface|null
     */
    public function getPrevious(mixed $id): ?OrderManagementStatusInterface;

    /**
     * Save an OrderManagementStatus record
     *
     * @param OrderManagementStatusInterface $orderManagementStatus
     * @return OrderManagementStatusInterface
     * @throws AlreadyExistsException
     */
    public function save(OrderManagementStatusInterface $orderManagementStatus): OrderManagementStatusInterface;

    /**
     * Delete an OrderManagementStatus record
     *
     * @param OrderManagementStatusInterface $orderManagementStatus
     * @return void
     */
    public function delete(OrderManagementStatusInterface $orderManagementStatus): void;

    /**
     * Search OrderManagementStatus records by search criteria
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @return OrderManagementStatusSearchResultInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): OrderManagementStatusSearchResultInterface;
}
