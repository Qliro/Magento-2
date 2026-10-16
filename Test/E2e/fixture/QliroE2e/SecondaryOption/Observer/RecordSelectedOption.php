<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace QliroE2e\SecondaryOption\Observer;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Filesystem;

/**
 * Records the option the update gets, and saves the quote when only the option changed
 */
class RecordSelectedOption implements ObserverInterface
{
    public function __construct(
        private readonly Filesystem $filesystem
    ) {
    }

    public function execute(Observer $observer)
    {
        $quote = $observer->getEvent()->getQuote();
        $container = $observer->getEvent()->getContainer();

        $directory = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_DIR);
        $path = 'qliro-e2e/secondary-option-' . $quote->getId() . '.json';
        $history = $directory->isFile($path) ? json_decode($directory->readFile($path), true) : [];

        $option = $container->getSecondaryOption();
        $last = $history ? end($history)['option'] : null;
        if ($option !== null && $option !== $last) {
            $container->setCanSaveQuote(true);
        }

        $history[] = [
            'method' => $container->getShippingMethod(),
            'option' => $option,
            'canSaveQuote' => (bool)$container->getCanSaveQuote(),
        ];
        $directory->writeFile($path, json_encode($history));
    }
}
