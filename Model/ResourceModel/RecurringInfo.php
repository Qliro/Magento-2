<?php declare(strict_types=1);

namespace Qliro\QliroOne\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class RecurringInfo extends AbstractDb
{
    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init('qliroone_recurring_info', 'entity_id');
    }
}
