<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace QliroE2e\SuccessPage\Observer;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Filesystem;

/**
 * Stands in for a server-side listener: one line per success event, by order
 */
class RecordSuccessEvent implements ObserverInterface
{
    public function __construct(
        private readonly Filesystem $filesystem
    ) {
    }

    public function execute(Observer $observer)
    {
        $orderIds = (array)$observer->getEvent()->getOrderIds();
        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $path = 'qliro-e2e/success-events-' . (int)reset($orderIds) . '.log';
        $before = $directory->isExist($path) ? $directory->readFile($path) : '';

        $directory->writeFile($path, $before . $observer->getEvent()->getName() . "\n");
    }
}
