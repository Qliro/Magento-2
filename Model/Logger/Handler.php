<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

// @codingStandardsIgnoreFile
// phpcs:ignoreFile

namespace Qliro\QliroOne\Model\Logger;

use Magento\Framework\DB\Adapter\DeadlockException;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use Monolog\Formatter\FormatterInterface;
use Qliro\QliroOne\Api\Data\LogRecordInterface;
use Qliro\QliroOne\Model\ResourceModel\LogRecord as DbLogRecord;

/**
 * Logger DB handler class
 */
class Handler extends AbstractProcessingHandler
{
    private const MAX_ATTEMPTS = 3;
    private const RETRY_DELAY_US = 50000;

    /**
     * @var ConnectionProvider
     */
    private $connectionProvider;

    /**
     * Handler constructor.
     *
     * @param FormatterInterface $formatter
     * @param ConnectionProvider $connectionProvider
     */
    public function __construct(
        FormatterInterface $formatter,
        ConnectionProvider $connectionProvider
    ) {
        $this->formatter = $formatter;
        $this->connectionProvider = $connectionProvider;

        parent::__construct();
    }

    /**
     * @param array|LogRecord $record
     */
    protected function write(array|LogRecord $record): void
    {
        try {
            $context = $record['context'];
            $formatted = $record['formatted'];

            $mark = $context['mark'] ?? null;
            $message = ($mark ? sprintf('%s: ', strtoupper((string)$mark)) : null) . $formatted['message'];

            $row = [
                LogRecordInterface::FIELD_DATE => date('Y-m-d H:i:s'),
                LogRecordInterface::FIELD_LEVEL => $formatted['level_name'],
                LogRecordInterface::FIELD_MESSAGE => $message,
                LogRecordInterface::FIELD_REFERENCE => $context['reference'] ?? '',
                LogRecordInterface::FIELD_TAGS => $context['tags'] ?? '',
                LogRecordInterface::FIELD_PROCESS_ID => $context['process_id'] ?? '',
                LogRecordInterface::FIELD_EXTRA => $this->encodeExtra($context['extra'] ?? ''),
            ];
        } catch (\Throwable $e) {
            return;
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $connection = $this->connectionProvider->getConnection();
                $connection->insert($connection->getTableName(DbLogRecord::TABLE_LOG), $row);

                return;
            } catch (DeadlockException $e) {
                if ($attempt < self::MAX_ATTEMPTS) {
                    usleep(self::RETRY_DELAY_US);
                }
            } catch (\Throwable $e) {
                // Never let logging break business flow
                return;
            }
        }
    }

    /**
     * @param array|string $data
     * @return string
     */
    private function encodeExtra($data)
    {
        try {
            $serializedData = is_array($data) ? $this->serialize($data) : $data;
        } catch (\Exception $exception) {
            $serializedData = null;
        }

        return $serializedData;
    }

    /**
     * Serialize JSON using pretty print and some other options
     *
     * @param array $data
     * @return false|string
     */
    private function serialize($data)
    {
        return \json_encode($data, JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE);
    }
}
