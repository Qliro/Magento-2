<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Logger\Processor;

use Monolog\LogRecord;
use Qliro\QliroOne\Model\Logger\Redactor;

/**
 * Masks every log line of the QliroOne channel before a handler can write it anywhere
 *
 * Registered on the channel rather than called from the log manager, so a line reaches the table
 * and the log files masked no matter who logged it. Handles both the array records of Monolog 2
 * and the objects of Monolog 3, because the module supports the Magento versions that ship each.
 */
class Redact
{
    /**
     * @param Redactor $redactor
     */
    public function __construct(
        private readonly Redactor $redactor
    ) {
    }

    /**
     * @param array|LogRecord $record
     * @return array|LogRecord
     */
    public function __invoke(array|LogRecord $record): array|LogRecord
    {
        try {
            return $this->redact($record);
        } catch (\Throwable $exception) {
            // Monolog lets an exception from a processor out into the business flow, and logging
            // must never do that. The line survives as a breadcrumb with nothing readable in it
            return $this->fallback($record, $exception);
        }
    }

    /**
     * @param array|LogRecord $record
     * @return array|LogRecord
     */
    private function redact(array|LogRecord $record): array|LogRecord
    {
        if ($record instanceof LogRecord) {
            $sensitive = $this->redactor->isSensitive($record->context['tags'] ?? '');

            return $record->with(
                message: $this->redactor->redactMessage($record->message, $sensitive),
                context: $this->redactor->redactContext($record->context, $sensitive),
                extra: $this->redactor->redactContext($record->extra, $sensitive)
            );
        }

        $context = (array)($record['context'] ?? []);
        $sensitive = $this->redactor->isSensitive($context['tags'] ?? '');

        $record['message'] = $this->redactor->redactMessage((string)($record['message'] ?? ''), $sensitive);
        $record['context'] = $this->redactor->redactContext($context, $sensitive);
        $record['extra'] = $this->redactor->redactContext((array)($record['extra'] ?? []), $sensitive);

        return $record;
    }

    /**
     * What is written when the masking itself failed: the line, without its payload
     *
     * @param array|LogRecord $record
     * @param \Throwable $exception
     * @return array|LogRecord
     */
    private function fallback(array|LogRecord $record, \Throwable $exception): array|LogRecord
    {
        $given = $record instanceof LogRecord ? $record->context : (array)($record['context'] ?? []);

        // The four keys the handler reads the row's own columns off, none of which is payload
        $context = ['redaction_failed' => get_class($exception)];

        foreach (['tags', 'reference', 'mark', 'process_id'] as $key) {
            if (isset($given[$key]) && is_scalar($given[$key])) {
                $context[$key] = $given[$key];
            }
        }

        if ($record instanceof LogRecord) {
            return $record->with(message: Redactor::MASK, context: $context, extra: []);
        }

        $record['message'] = Redactor::MASK;
        $record['context'] = $context;
        $record['extra'] = [];

        return $record;
    }
}
