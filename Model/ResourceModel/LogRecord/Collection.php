<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\ResourceModel\LogRecord;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Qliro\QliroOne\Model\LogRecord;
use Qliro\QliroOne\Model\ResourceModel\LogRecord as LogRecordResource;

class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'id'; // primary key column in qliroone_log

    protected function _construct(): void
    {
        $this->_init(LogRecord::class, LogRecordResource::class);
    }
}