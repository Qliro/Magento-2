<?php declare(strict_types=1);

namespace Qliro\QliroOne\Model\ResourceModel\RecurringInfo;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    /**
     * @inheritDoc
     */
    protected $_idFieldName = 'entity_id';

    /**
     * @inheritDoc
     */
    protected function _construct(): void
    {
        $this->_init(
            \Qliro\QliroOne\Model\RecurringInfo::class,
            \Qliro\QliroOne\Model\ResourceModel\RecurringInfo::class
        );
    }
}
