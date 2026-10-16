<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace QliroE2e\SecondaryOption\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Qliro\QliroOne\Api\Data\QliroOrderShippingMethodOptionInterfaceFactory;

/**
 * Adds pickup points to Flat Rate on the e2e product's quotes, the way a merchant adds its own options
 */
class AddPickupPoints implements ObserverInterface
{
    public const METHOD = 'flatrate_flatrate';
    public const SKU = 'QLIRO-E2E-PRODUCT';
    public const POINTS = ['pickup-point-1' => 'Pickup point 1', 'pickup-point-2' => 'Pickup point 2'];

    public function __construct(
        private readonly QliroOrderShippingMethodOptionInterfaceFactory $optionFactory
    ) {
    }

    public function execute(Observer $observer)
    {
        $container = $observer->getEvent()->getContainer();

        if ($container->getMerchantReference() !== self::METHOD || !$this->hasE2eProduct($observer->getEvent()->getQuote())) {
            return;
        }

        $options = [];
        foreach (self::POINTS as $reference => $name) {
            $options[] = $this->optionFactory->create()
                ->setMerchantReference($reference)
                ->setDisplayName($name)
                ->setDescriptions([$name]);
        }

        $container->setSecondaryOptions($options);
    }

    private function hasE2eProduct($quote): bool
    {
        foreach ($quote->getAllVisibleItems() as $item) {
            if ($item->getSku() === self::SKU) {
                return true;
            }
        }

        return false;
    }
}
