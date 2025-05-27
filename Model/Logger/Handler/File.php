<?php declare(strict_types=1);

namespace Qliro\QliroOne\Model\Logger\Handler;

use Magento\Framework\Logger\Handler\Base as BaseHandler;

/**
 * General log to file handler
 */
class File extends BaseHandler
{
    /**
     * {@inheirtDoc}
     */
    protected $fileName = '/var/log/qliroone.log';
}
