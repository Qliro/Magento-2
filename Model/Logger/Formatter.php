<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Logger;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

class Formatter implements FormatterInterface
{
    private int $maxNestingLevel;
    private bool $exceptionTraceAsString;

    /**
     * Initialize formatter
     *
     * @param int  $maxNestingLevel 0 means infinite nesting, the $record itself is level 1, $record['context'] is 2
     * @param bool $exceptionTraceAsString set to false to log exception traces as a sub documents instead of strings
     */
    public function __construct(
        int $maxNestingLevel = 3,
        bool $exceptionTraceAsString = true
    ) {
        $this->maxNestingLevel = max($maxNestingLevel, 0);
        $this->exceptionTraceAsString = $exceptionTraceAsString;
    }

    /**
     * {@inheritDoc}
     */
    public function format(array|LogRecord $record): mixed
    {
        return $this->formatArray(
            $record instanceof LogRecord ? $record->toArray() : $record,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function formatBatch(array $records): array
    {
        foreach ($records as $key => $record) {
            $records[$key] = $this->format($record);
        }

        return $records;
    }

    /**
     * @param array $record
     * @param int $nestingLevel
     * @return array|string
     */
    private function formatArray(array $record, int $nestingLevel = 0): array|string
    {
        if ($this->maxNestingLevel == 0 || $nestingLevel <= $this->maxNestingLevel) {
            foreach ($record as $name => $value) {
                if ($value instanceof \DateTime) {
                    $record[$name] = $this->formatDate($value, $nestingLevel + 1);
                } elseif ($value instanceof \Exception) {
                    $record[$name] = $this->formatException($value, $nestingLevel + 1);
                } elseif (is_array($value)) {
                    $record[$name] = $this->formatArray($value, $nestingLevel + 1);
                } elseif (is_object($value)) {
                    $record[$name] = $this->formatObject($value, $nestingLevel + 1);
                }
            }
        } else {
            $record = '[...]';
        }

        return $record;
    }

    /**
     * @param object $value
     * @param int $nestingLevel
     * @return array|string
     */
    private function formatObject(object $value, int $nestingLevel): array|string
    {
        $objectVars = get_object_vars($value);
        $objectVars['class'] = get_class($value);

        return $this->formatArray($objectVars, $nestingLevel);
    }

    /**
     * @param \Exception $exception
     * @param int $nestingLevel
     * @return array|string
     */
    private function formatException(\Exception $exception, int $nestingLevel): array|string
    {
        $formattedException = [
            'class' => get_class($exception),
            'message' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'file' => $exception->getFile() . ':' . $exception->getLine(),
        ];

        if ($this->exceptionTraceAsString === true) {
            $formattedException['trace'] = $exception->getTraceAsString();
        } else {
            $formattedException['trace'] = $exception->getTrace();
        }

        return $this->formatArray($formattedException, $nestingLevel);
    }

    /**
     * @param \DateTime $value
     * @param int $nestingLevel
     * @return string
     */
    private function formatDate(\DateTime $value, int $nestingLevel): string
    {
        return $value->format('Y-m-d H:i:s.u');
    }
}
