<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace QliroE2e\FailingOrderSave\Plugin;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Fails the next save of an order the test named in var/qliro-e2e/fail-order-save-<increment id>,
 * once, as a lock wait timeout or a third-party observer would
 */
class FailOnce
{
    /**
     * @param DirectoryList $directoryList
     */
    public function __construct(private readonly DirectoryList $directoryList)
    {
    }

    /**
     * @param OrderRepositoryInterface $subject
     * @param OrderInterface $order
     * @return null
     */
    public function beforeSave(OrderRepositoryInterface $subject, OrderInterface $order)
    {
        $file = $this->directoryList->getPath(DirectoryList::VAR_DIR)
            . '/qliro-e2e/fail-order-save-' . $order->getIncrementId();

        if (is_file($file)) {
            unlink($file);

            throw new \RuntimeException('The order save failed on purpose');
        }

        return null;
    }
}
