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
use Qliro\QliroOne\Api\Data\LinkInterface;

/**
 * Link repository interface
 *
 * @api
 */
interface LinkRepositoryInterface
{
    /**
     * Get a link by its ID
     *
     * @param int $id
     * @param bool $onlyActive
     * @return LinkInterface
     * @throws NoSuchEntityException
     */
    public function get(int $id, bool $onlyActive = true): LinkInterface;

    /**
     * Get a link by Magento quote ID
     *
     * @param int|string $quoteId
     * @param bool $onlyActive
     * @return LinkInterface
     * @throws NoSuchEntityException
     */
    public function getByQuoteId(int|string $quoteId, bool $onlyActive = true): LinkInterface;

    /**
     * Get a link by Magento order ID
     *
     * @param int|string $orderId
     * @param bool $onlyActive
     * @return LinkInterface
     * @throws NoSuchEntityException
     */
    public function getByOrderId(int|string $orderId, bool $onlyActive = true): LinkInterface;

    /**
     * Get a link by Qliro order ID
     *
     * @param mixed $qliroOrderId
     * @param bool $onlyActive
     * @return LinkInterface
     * @throws NoSuchEntityException
     */
    public function getByQliroOrderId(mixed $qliroOrderId, bool $onlyActive = true): LinkInterface;

    /**
     * Get a link by generated order reference
     *
     * @param string $reference
     * @param bool $onlyActive
     * @return LinkInterface
     * @throws NoSuchEntityException
     */
    public function getByReference(string $reference, bool $onlyActive = true): LinkInterface;

    /**
     * Save a link
     *
     * @param LinkInterface $link
     * @return LinkInterface
     * @throws AlreadyExistsException
     */
    public function save(LinkInterface $link): LinkInterface;

    /**
     * Delete a link
     *
     * @param LinkInterface $link
     * @return void
     */
    public function delete(LinkInterface $link): void;

    /**
     * Search links by criteria
     *
     * @param SearchCriteriaInterface $searchCriteria
     * @return LinkSearchResultInterface
     */
    public function getList(SearchCriteriaInterface $searchCriteria): LinkSearchResultInterface;

    /**
     * Lock a link to prevent cart modification during payment
     *
     * @param int $quoteId
     * @return LinkInterface
     * @throws AlreadyExistsException
     * @throws NoSuchEntityException
     */
    public function lock(int $quoteId): LinkInterface;

    /**
     * Unlock a link to allow cart modification again
     *
     * @param int $quoteId
     * @return LinkInterface
     * @throws AlreadyExistsException
     * @throws NoSuchEntityException
     */
    public function unlock(int $quoteId): LinkInterface;
}
