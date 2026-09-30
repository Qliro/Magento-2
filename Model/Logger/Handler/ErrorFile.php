<?php declare(strict_types=1);

namespace Qliro\QliroOne\Model\Logger\Handler;

use Magento\Framework\Logger\Handler\Base as BaseHandler;

/**
 * Handler for logging errors to specific file
 */
class ErrorFile extends BaseHandler
{
    /**
     * {@inheritDoc}
     */
    protected $fileName = '/var/log/qliroone_error.log';

    /**
     * {@inheritDoc}
     */
    protected $loggerType = \Monolog\Logger::ERROR;
}
