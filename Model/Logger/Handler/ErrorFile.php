<?php declare(strict_types=1);

namespace Qliro\QliroOne\Model\Logger\Handler;

use Magento\Framework\Logger\Handler\Base as BaseHandler;

/**
 * Handler for logging errors to specific file
 */
class ErrorFile extends BaseHandler
{
    /**
     * {@inheirtDoc}
     */
    protected $fileName = '/var/log/qliroone_error.log';

    /**
     * {@inheirtDoc}
     */
    protected $loggerType = \Monolog\Logger::ERROR;
}
