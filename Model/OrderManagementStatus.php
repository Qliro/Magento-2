<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model;

use Magento\Framework\Model\AbstractModel;
use Qliro\QliroOne\Api\Data\OrderManagementStatusInterface;

/**
 * OrderManagementStatus record model class
 */
class OrderManagementStatus extends AbstractModel implements OrderManagementStatusInterface
{
    protected function _construct(): void
    {
        $this->_init(ResourceModel\OrderManagementStatus::class);
    }

    /**
     * @inHeirtDoc
     */
    public function getId(): mixed
    {
        return $this->getData(self::FIELD_ID);
    }

    /**
     * @inHeirtDoc
     */
    public function getDate(): string
    {
        return (string)$this->getData(self::FIELD_DATE);
    }

    /**
     * @inHeirtDoc
     */
    public function getTransactionId(): int
    {
        return (int)$this->getData(self::FIELD_TRANSACTION_ID);
    }

    /**
     * @inHeirtDoc
     */
    public function getRecordType(): string
    {
        return (string)$this->getData(self::FIELD_RECORD_TYPE);
    }

    /**
     * @inHeirtDoc
     */
    public function getRecordId(): ?int
    {
        $value = $this->getData(self::FIELD_RECORD_ID);
        return $value !== null ? (int)$value : null;
    }

    /**
     * @inHeirtDoc
     */
    public function getTransactionStatus(): string
    {
        return (string)$this->getData(self::FIELD_TRANSACTION_STATUS);
    }

    /**
     * @inHeirtDoc
     */
    public function getNotificationStatus(): string
    {
        return (string)$this->getData(self::FIELD_NOTIFICATION_STATUS);
    }

    /**
     * @inHeirtDoc
     */
    public function getMessage(): string
    {
        return (string)$this->getData(self::FIELD_MESSAGE);
    }

    /**
     * @inHeirtDoc
     */
    public function getQliroOrderId(): int
    {
        return (int)$this->getData(self::FIELD_QLIRO_ORDER_ID);
    }

    /**
     * @inHeirtDoc
     */
    public function setId(mixed $value): static
    {
        return $this->setData(self::FIELD_ID, $value);
    }

    /**
     * @inHeirtDoc
     */
    public function setDate(mixed $value): static
    {
        return $this->setData(self::FIELD_DATE, $value);
    }

    /**
     * @inHeirtDoc
     */
    public function setTransactionId(mixed $value): static
    {
        return $this->setData(self::FIELD_TRANSACTION_ID, $value);
    }

    /**
     * @inHeirtDoc
     */
    public function setRecordType(mixed $value): static
    {
        return $this->setData(self::FIELD_RECORD_TYPE, $value);
    }

    /**
     * @inHeirtDoc
     */
    public function setRecordId(mixed $value): static
    {
        return $this->setData(self::FIELD_RECORD_ID, $value);
    }

    /**
     * @inHeirtDoc
     */
    public function setTransactionStatus(mixed $value): static
    {
        return $this->setData(self::FIELD_TRANSACTION_STATUS, $value);
    }

    /**
     * @inHeirtDoc
     */
    public function setNotificationStatus(mixed $value): static
    {
        return $this->setData(self::FIELD_NOTIFICATION_STATUS, $value);
    }

    /**
     * @inHeirtDoc
     */
    public function setMessage(mixed $value): static
    {
        return $this->setData(self::FIELD_MESSAGE, $value);
    }

    /**
     * @inHeirtDoc
     */
    public function setQliroOrderId(mixed $id): static
    {
        return $this->setData(self::FIELD_QLIRO_ORDER_ID, $id);
    }
}
