<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Log record data interface
 *
 * @api
 */
interface LogRecordInterface
{
    const string FIELD_ID = 'id';
    const string FIELD_DATE = 'date';
    const string FIELD_PROCESS_ID = 'process_id';
    const string FIELD_REFERENCE = 'reference';
    const string FIELD_TAGS = 'tags';
    const string FIELD_MESSAGE = 'message';
    const string FIELD_EXTRA = 'extra';
    const string FIELD_LEVEL = 'level';

    /**
     * @return mixed
     */
    public function getId();

    /**
     * @return string|null
     */
    public function getDate(): ?string;

    /**
     * @return string|null
     */
    public function getMessage(): ?string;

    /**
     * @return string|null
     */
    public function getLevel(): ?string;

    /**
     * @return string|null
     */
    public function getProcessId(): ?string;

    /**
     * @return string|null
     */
    public function getTag(): ?string;

    /**
     * @return string|null
     */
    public function getExtra(): ?string;

    /**
     * @param mixed $id
     */
    public function setId($id);

    /**
     * @param string $date
     * @return static
     */
    public function setDate(string $date): static;

    /**
     * @param string $message
     * @return static
     */
    public function setMessage(string $message): static;

    /**
     * @param string $value
     * @return static
     */
    public function setLevel(string $value): static;

    /**
     * @param string $process_id
     * @return static
     */
    public function setProcessId(string $process_id): static;

    /**
     * @param string $tag
     * @return static
     */
    public function setTag(string $tag): static;

    /**
     * @param string $extra
     * @return static
     */
    public function setExtra(string $extra): static;
}
