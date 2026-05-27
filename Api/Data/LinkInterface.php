<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Quote/Order/QliroOne Order link interface
 *
 * @api
 */
interface LinkInterface
{
    const string FIELD_ID = 'link_id';
    const string FIELD_IS_ACTIVE = 'is_active';
    const string FIELD_REFERENCE = 'reference';
    const string FIELD_QUOTE_ID = 'quote_id';
    const string FIELD_QLIRO_ORDER_ID = 'qliro_order_id';
    const string FIELD_QLIRO_ORDER_STATUS = 'qliro_order_status';
    const string FIELD_ORDER_ID = 'order_id';
    const string FIELD_QUOTE_SNAPSHOT = 'quote_snapshot';
    const string FIELD_REMOTE_IP = 'remote_ip';
    const string FIELD_CREATED_AT = 'created_at';
    const string FIELD_UPDATED_AT = 'updated_at';
    const string FIELD_MESSAGE= 'message';
    const string FIELD_PLACED_AT = 'placed_at';
    const string FIELD_UNIFAUN_SHIPPING_AMOUNT = 'unifaun_shipping_amount';
    const string FIELD_INGRID_SHIPPING_AMOUNT = 'ingrid_shipping_amount';
    const string FIELD_IS_LOCKED = 'is_locked';

    /**
     * Get ID
     *
     * @return mixed
     */
    public function getId();

    /**
     * Get "is_active" flag
     *
     * @return int
     */
    public function getIsActive(): int;

    /**
     * Get a unique reference hash
     *
     * @return string
     */
    public function getReference(): string;

    /**
     * Get Magento quote ID
     *
     * @return int|null
     */
    public function getQuoteId(): ?int;

    /**
     * Get Magento order ID
     *
     * @return int|null
     */
    public function getOrderId(): ?int;

    /**
     * Get QliroOne order ID
     *
     * @return int|null
     */
    public function getQliroOrderId(): ?int;

    /**
     * Get QliroOne order status
     *
     * @return string|null
     */
    public function getQliroOrderStatus(): ?string;

    /**
     * Get client ip when the link was created
     *
     * @return string
     */
    public function getRemoteIp(): string;

    /**
     * Get creation timestamp
     *
     * @return string
     */
    public function getCreatedAt(): string;

    /**
     * Get the timestamp of the last update
     *
     * @return string
     */
    public function getUpdatedAt(): string;

    /**
     * Get a timestamp of when to start pending view, basically when qliro has placed the order
     *
     * @return string
     */
    public function getPlacedAt(): string;

    /**
     * Get hash reflecting qliro order
     *
     * @return string
     */
    public function getQuoteSnapshot(): string;

    /**
     * Get message
     *
     * @return string
     */
    public function getMessage(): string;

    /**
     * Get unifaun shipping amount
     *
     * @return float
     */
    public function getUnifaunShippingAmount(): float;

    /**
     * Get ingrid shipping amount
     *
     * @return float|null
     */
    public function getIngridShippingAmount(): ?float;

    /**
     * Get "is_locked" flag
     *
     * @return bool
     */
    public function getIsLocked(): bool;

    /**
     * Set ID
     *
     * @param mixed $value
     * @return $this
     */
    public function setId($value);

    /**
     * Set "is_active" flag
     *
     * @param int|bool $value
     * @return $this
     */
    public function setIsActive(int|bool $value): static;

    /**
     * Set a unique reference hash
     *
     * @param string $value
     * @return $this
     */
    public function setReference(string $value): static;

    /**
     * Set Magento quote ID
     *
     * @param int|string $value
     * @return $this
     */
    public function setQuoteId(int|string $value): static;

    /**
     * Set Magento order ID
     *
     * @param int|string $value
     * @return $this
     */
    public function setOrderId(int|string $value): static;

    /**
     * Set QliroOne order ID
     *
     * @param int|string|null $value Accepts int or numeric string from the Qliro API; stored as int.
     * @return $this
     */
    public function setQliroOrderId(int|string|null $value): static;

    /**
     * Set QliroOne order status
     *
     * @param string $value
     * @return $this
     */
    public function setQliroOrderStatus(string $value): static;

    /**
     * Set client ip
     *
     * @param string $value
     * @return $this
     */
    public function setRemoteIp(string $value): static;

    /**
     * Set the creation timestamp
     *
     * @param string $value
     * @return $this
     */
    public function setCreatedAt(string $value): static;

    /**
     * Set the timestamp of the last update
     *
     * @param string $value
     * @return $this
     */
    public function setUpdatedAt(string $value): static;

    /**
     * Set the timestamp of when we start pending view, basically when qliro has placed the order
     *
     * @param string $value
     * @return $this
     */
    public function setPlacedAt(string $value): static;

    /**
     * Set hash reflecting qliro order
     *
     * @param string|null $value
     * @return $this
     */
    public function setQuoteSnapshot(?string $value): static;

    /**
     * Set message
     *
     * @param string $value
     * @return $this
     */
    public function setMessage(string $value): static;

    /**
     * Set unifaun shipping amount
     *
     * @param float $value
     * @return $this
     */
    public function setUnifaunShippingAmount(float $value): static;

    /**
     * Set ingrid shipping amount
     *
     * @param float|null $value
     * @return $this
     */
    public function setIngridShippingAmount(?float $value): static;

    /**
     * Set "is_locked" flag
     *
     * @param bool $value
     * @return $this
     */
    public function setIsLocked(bool $value): static;
}
