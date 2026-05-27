<?php
declare(strict_types=1);

namespace Qliro\QliroOne\Plugin;

use Magento\Sales\Model\ResourceModel\Order\Grid\Collection as OrderGridCollection;

class AddRecurringColumnToOrderGrid
{
    public function beforeLoad(OrderGridCollection $collection): void
    {
        $select = $collection->getSelect();
        $fromPart = $select->getPart(\Zend_Db_Select::FROM);
        if (isset($fromPart['recurring_info'])) {
            return;
        }

        $select->joinLeft(
            ['recurring_info' => $collection->getTable('qliroone_recurring_info')],
            'main_table.entity_id = recurring_info.original_order_id',
            []
        );

        $select->columns([
            'is_recurring' => new \Zend_Db_Expr(
                'IF(recurring_info.original_order_id IS NOT NULL AND recurring_info.next_order_date IS NOT NULL, "Yes", "No")'
            ),
        ]);
    }
}
