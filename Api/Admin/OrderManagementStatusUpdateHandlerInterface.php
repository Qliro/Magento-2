<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Admin;

use Qliro\QliroOne\Model\OrderManagementStatus;

/**
 * Order Management Status transaction update handler interface
 *
 * @api
 */
interface OrderManagementStatusUpdateHandlerInterface
{
    /**
     * Handle a successful transaction status notification
     *
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @return void
     */
    public function handleSuccess(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void;

    /**
     * Handle a cancelled transaction status notification
     *
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @return void
     */
    public function handleCancelled(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void;

    /**
     * Handle an error transaction status notification
     *
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @return void
     */
    public function handleError(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void;

    /**
     * Handle an in-process transaction status notification
     *
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @return void
     */
    public function handleInProcess(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void;

    /**
     * Handle an on-hold transaction status notification
     *
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @return void
     */
    public function handleOnHold(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void;

    /**
     * Handle a user-interaction-required transaction status notification
     *
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @return void
     */
    public function handleUserInteraction(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void;

    /**
     * Handle a created transaction status notification
     *
     * @param array $qliroOrderManagementStatus
     * @param OrderManagementStatus $omStatus
     * @return void
     */
    public function handleCreated(array $qliroOrderManagementStatus, OrderManagementStatus $omStatus): void;
}
