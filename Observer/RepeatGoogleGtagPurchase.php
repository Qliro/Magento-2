<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\View\LayoutInterface;

/**
 * Gives Magento's own GA4 block the order on a repeated success render, as its observer does on
 * core's event. By block name, so a store without Magento_GoogleGtag is left alone.
 */
class RepeatGoogleGtagPurchase implements ObserverInterface
{
    /**
     * @var \Magento\Framework\View\LayoutInterface
     */
    private $layout;

    /**
     * @param \Magento\Framework\View\LayoutInterface $layout
     */
    public function __construct(LayoutInterface $layout)
    {
        $this->layout = $layout;
    }

    /**
     * @param \Magento\Framework\Event\Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $orderIds = $observer->getEvent()->getOrderIds();

        if (empty($orderIds) || !is_array($orderIds)) {
            return;
        }

        $block = $this->layout->getBlock('google_gtag_analytics');

        if ($block) {
            $block->setOrderIds($orderIds);
        }
    }
}
