<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\OrderManagementStatus\Update;

use Qliro\QliroOne\Api\Admin\OrderManagementStatusUpdateHandlerInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;

/**
 * Class HandlerPool, all handlers available to deal with Order Management Status Notifications sent from Qliro
 */
class HandlerPool
{
    private $handlerStatusMap = [
        'Success' => 'handleSuccess',
        'Cancelled' => 'handleCancelled',
        'Error' => 'handleError',
        'InProcess' => 'handleInProcess',
        'OnHold' => 'handleOnHold',
        'UserInteraction' => 'handleUserInteraction',
        'Created' => 'handleCreated',
    ];

    /**
     * Class constructor
     *
     * @param \Qliro\QliroOne\Model\Logger\Manager $logManager
     * @param array $handlerPool
     */
    public function __construct(
        private readonly LogManager $logManager,
        private readonly array $handlerPool = []
    ) {
    }

    /**
     * If a handler is found, figure out what status it is and call the selected handler for it
     * Returns true if it was handled, otherwise it returns false
     *
     * @param \Qliro\QliroOne\Model\Notification\QliroOrderManagementStatus $qliroOrderManagementStatus
     * @param \Qliro\QliroOne\Model\OrderManagementStatus $omStatus
     * @return bool
     */
    public function handle($qliroOrderManagementStatus, $omStatus)
    {
        try {
            $type = $omStatus->getRecordType();
            // Null means it used to throw an exception and log it. Type is always null initially, no point in logging
            if ($type === null) {
                return false;
            }
            $handler = $this->handlerPool[$type] ?? null;
            if ($handler instanceof OrderManagementStatusUpdateHandlerInterface) {
                $handlerFunction = $this->handlerStatusMap[$qliroOrderManagementStatus['Status'] ?? null];
                if ($handlerFunction) {
                    $handler->$handlerFunction($qliroOrderManagementStatus, $omStatus);
                } else {
                    throw new \LogicException('No status function for OrderManagementStatus handler available');
                }
            } else {
                throw new \LogicException('No Handler for OrderManagementStatus available');
            }

        } catch (\Exception $exception) {
            $this->logManager->debug(
                $exception,
                [
                    'extra' => [
                        'type' => $type,
                        'status' => $qliroOrderManagementStatus['Status'] ?? null,
                    ],
                ]
            );

            return false;
        }

        return true;
    }
}
