<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * OrderManagementStatus interface
 *
 * @api
 */
interface OrderManagementStatusInterface
{
    const string FIELD_ID = 'id';
    const string FIELD_DATE = 'date';
    const string FIELD_TRANSACTION_ID = 'transaction_id';
    const string FIELD_RECORD_TYPE = 'record_type';
    const string FIELD_RECORD_ID = 'record_id';
    const string FIELD_TRANSACTION_STATUS = 'transaction_status';
    const string FIELD_MESSAGE = 'message';
    const string FIELD_NOTIFICATION_STATUS = 'notification_status';
    const string FIELD_QLIRO_ORDER_ID = 'qliro_order_id';

    /**
     * Magento record types initiating the notification
     */
    const string RECORD_TYPE_SHIPMENT = 'shipment';
    const string RECORD_TYPE_PAYMENT = 'payment';
    const string RECORD_TYPE_REFUND = 'refund';
    const string RECORD_TYPE_CANCEL = 'cancel';

    /**
     * Internal status of order management status transaction update
     */
    const string NOTIFICATION_STATUS_DONE = 'handled';
    const string NOTIFICATION_STATUS_NEW = 'new';
    const string NOTIFICATION_STATUS_ERROR = 'exception';
    const string NOTIFICATION_STATUS_SKIPPED = 'skipped';

    /**
     * Get ID
     *
     * @return mixed
     */
    public function getId(): mixed;

    /**
     * @return string|null
     */
    public function getDate(): ?string;

    /**
     * @return int|null
     */
    public function getTransactionId(): ?int;

    /**
     * One of the defined record types declared above
     *
     * @return string|null
     */
    public function getRecordType(): ?string;

    /**
     * @return int|null
     */
    public function getRecordId(): ?int;

    /**
     * @return string|null
     */
    public function getTransactionStatus(): ?string;

    /**
     * @return string|null
     */
    public function getNotificationStatus(): ?string;

    /**
     * @return string|null
     */
    public function getMessage(): ?string;

    /**
     * @return int|null
     */
    public function getQliroOrderId(): ?int;

    /**
     * @param mixed $value
     * @return static
     */
    public function setId(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setDate(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setTransactionId(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setRecordType(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setRecordId(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setTransactionStatus(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setNotificationStatus(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setMessage(mixed $value): static;

    /**
     * @param mixed $id
     * @return static
     */
    public function setQliroOrderId(mixed $id): static;
}
