<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api;

use Magento\Framework\Model\AbstractModel;
use Qliro\QliroOne\Api\Data\RecurringInfoInterface;

/**
 * Recurring Info repository interface
 *
 * @api
 */
interface RecurringInfoRepositoryInterface
{
    /**
     * Save a recurring info record
     *
     * @param RecurringInfoInterface $recurringInfo
     * @return void
     * @throws \Exception
     */
    public function save(Data\RecurringInfoInterface $recurringInfo): void;

    /**
     * Get recurring info record by the original order ID
     *
     * @param int $orderId
     * @return Data\RecurringInfoInterface
     */
    public function getByOriginalOrderId(int $orderId): Data\RecurringInfoInterface;

    /**
     * Get a recurring info record by recurring token
     *
     * @param string $recurringToken
     * @return Data\RecurringInfoInterface
     */
    public function getByRecurringToken(string $recurringToken): Data\RecurringInfoInterface;

    /**
     * Get recurring orders scheduled for today, optionally filtered by store
     *
     * @param int|string|null $storeId
     * @return RecurringInfoInterface[]
     */
    public function getByTodaysDate(int|string|null $storeId = null): array;
}
