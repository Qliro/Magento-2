<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Qliro\QliroOne\Model\ResourceModel\RecurringInfo\CollectionFactory;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Template;
use Qliro\QliroOne\Api\Data\RecurringInfoInterface;
use Qliro\QliroOne\Model\RecurringInfo;

/**
 * Recurring History View Model
 */
class RecurringHistory implements ArgumentInterface
{
    const CANCEL_RECURRING_ROUTE = 'checkout/qliro_recurring/cancel';

    const ORDER_VIEW_ROUTE = 'sales/order/view';

    private CollectionFactory $recurringCollectionFactory;

    private CustomerSession $customerSession;

    private ?Template $containerBlock = null;

    public function __construct(
        CollectionFactory $recurringCollectionFactory,
        CustomerSession $customerSession
    ) {
        $this->recurringCollectionFactory = $recurringCollectionFactory;
        $this->customerSession = $customerSession;
    }

    /**
     * Gets Recurring Infos for the logged in customer
     *
     * @return RecurringInfo[]
     */
    public function getRecurringInfos(): array
    {
        $customerId = $this->customerSession->getCustomerId();
        $collection = $this->recurringCollectionFactory->create();
        $collection->join(
            ['sales_order' => $collection->getTable('sales_order')],
            'main_table.original_order_id = sales_order.entity_id AND sales_order.customer_id = :customer_id',
            ['increment_id', 'created_at']
        );
        $collection->addBindParam('customer_id', $customerId);
        return $collection->getItems();
    }

    /**
     * Url to the Cancel Recurring Controller
     *
     * @return string
     */
    public function getCancelRecurringUrl(): string
    {
        return $this->containerBlock->getUrl(
            self::CANCEL_RECURRING_ROUTE
        );
    }

    /**
     * @param RecurringInfoInterface $recurringInfo
     * @return string
     */
    public function getViewOrderUrl(RecurringInfoInterface $recurringInfo): string
    {
        return $this->containerBlock->getUrl(
            self::ORDER_VIEW_ROUTE,
            ['order_id' => $recurringInfo->getOriginalOrderId()]
        );
    }

    /**
     * @param RecurringInfoInterface $recurringInfo
     * @return bool
     */
    public function isCanceled(RecurringInfoInterface $recurringInfo): bool
    {
        return !!$recurringInfo->getCanceledDate();
    }

    /**
     * @param Template $block
     * @return void
     */
    public function setContainerBlock(Template $block): void
    {
        $this->containerBlock = $block;
    }

    /**
     * @param string $date
     * @return string
     */
    public function getFormattedDate(string $date): string
    {
        $dateObj = new \DateTime($date);
        $dateObj->setTime(0, 0); // Add time, otherwise the displayed date can be wrong
        return $this->containerBlock->formatDate($dateObj, \IntlDateFormatter::MEDIUM);
    }
}
