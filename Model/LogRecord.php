<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model;

use Magento\Framework\Model\AbstractModel;
use Qliro\QliroOne\Api\Data\LogRecordInterface;

/**
 * Log record model class
 */
class LogRecord extends AbstractModel implements LogRecordInterface
{
    /**
     * Initialize a resource model
     */
    protected function _construct(): void
    {
        $this->_init(ResourceModel\LogRecord::class);
    }

    /**
     * @inheritdoc
     */
    public function getDate(): string
    {
        return (string)$this->getData(self::FIELD_DATE);
    }

    /**
     * @inheritdoc
     */
    public function getMessage(): string
    {
        return (string)$this->getData(self::FIELD_MESSAGE);
    }

    /**
     * @inheritdoc
     */
    public function getExtra(): string
    {
        return (string)$this->getData(self::FIELD_EXTRA);
    }

    /**
     * @inheritdoc
     */
    public function getLevel(): string
    {
        return (string)$this->getData(self::FIELD_LEVEL);
    }

    /**
     * @inheritdoc
     */
    public function getTag(): string
    {
        return (string)$this->getData(self::FIELD_TAGS);
    }

    /**
     * @inheritdoc
     */
    public function getProcessId(): string
    {
        return (string)$this->getData(self::FIELD_PROCESS_ID);
    }

    /**
     * @inheritdoc
     */
    public function setDate(string $date): static
    {
        return $this->setData(self::FIELD_DATE, $date);
    }

    /**
     * @inheritdoc
     */
    public function setMessage(string $message): static
    {
        return $this->setData(self::FIELD_MESSAGE, $message);
    }

    /**
     * @inheritdoc
     */
    public function setLevel($value): static
    {
        return $this->setData(self::FIELD_LEVEL, $value);
    }

    /**
     * @inheritdoc
     */
    public function setTag(string $tag): static
    {
        return $this->setData(self::FIELD_TAGS, $tag);
    }

    /**
     * @inheritdoc
     */
    public function setProcessId(string $process_id): static
    {
        return $this->setData(self::FIELD_PROCESS_ID, $process_id);
    }

    /**
     * @inheritdoc
     */
    public function setExtra(string $extra): static
    {
        return $this->setData(self::FIELD_EXTRA, $extra);
    }
}
