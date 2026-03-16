<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Api\Data;

/**
 * Quote/Order/QliroOne Order link interface
 *
 * @api
 */
interface LinkInterface
{
    const FIELD_ID = 'link_id';
    const FIELD_IS_ACTIVE = 'is_active';
    const FIELD_REFERENCE = 'reference';
    const FIELD_QUOTE_ID = 'quote_id';
    const FIELD_QLIRO_ORDER_ID = 'qliro_order_id';
    const FIELD_QLIRO_ORDER_STATUS = 'qliro_order_status';
    const FIELD_ORDER_ID = 'order_id';
    const FIELD_QUOTE_SNAPSHOT = 'quote_snapshot';
    const FIELD_REMOTE_IP = 'remote_ip';
    const FIELD_CREATED_AT = 'created_at';
    const FIELD_UPDATED_AT = 'updated_at';
    const FIELD_MESSAGE= 'message';
    const FIELD_PLACED_AT = 'placed_at';
    const FIELD_UNIFAUN_SHIPPING_AMOUNT = 'unifaun_shipping_amount';
    const FIELD_INGRID_SHIPPING_AMOUNT = 'ingrid_shipping_amount';
    const FIELD_IS_LOCKED = 'is_locked';

    /**
     * Get ID
     *
     * @return mixed
     */
    public function getId(): mixed;

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
     * @return string|null
     */
    public function getQuoteId(): ?string;

    /**
     * Get Magento order ID
     *
     * @return int|null
     */
    public function getOrderId(): ?int;

    /**
     * Get QliroOne order ID
     *
     * @return string|null
     */
    public function getQliroOrderId(): ?string;

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
     * @return ?float
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
    public function setId(mixed $value): static;

    /**
     * Set "is_active" flag
     *
     * @return $this
     *@var int $value
     */
    public function setIsActive(int $value): static;

    /**
     * Set a unique reference hash
     *
     * @return $this
     *@var string $value
     */
    public function setReference(string $value): static;

    /**
     * Set Magento quote ID
     *
     * @return $this
     *@var int $value
     */
    public function setQuoteId(int $value): static;

    /**
     * Set Magento order ID
     *
     * @return $this
     *@var int $value
     */
    public function setOrderId(int $value): static;

    /**
     * Set QliroOne order ID
     *
     * @return $this
     *@var string $value
     */
    public function setQliroOrderId(string $value): static;

    /**
     * Set QliroOne order status
     *
     * @return $this
     *@var string $value
     */
    public function setQliroOrderStatus(string $value): static;

    /**
     * Set client ip
     *
     * @return $this
     *@var string $value
     */
    public function setRemoteIp(string $value): static;

    /**
     * Set the creation timestamp
     *
     * @return $this
     *@var string $value
     */
    public function setCreatedAt(string $value): static;

    /**
     * Set the timestamp of the last update
     *
     * @return $this
     *@var string $value
     */
    public function setUpdatedAt(string $value): static;

    /**
     * Set the timestamp of when we start pending view, basically when qliro has placed the order
     *
     * @return $this
     *@var string $value
     */
    public function setPlacedAt(string $value);

    /**
     * Set hash reflecting qliro order
     *
     * @return $this
     *@var ?string $value
     */
    public function setQuoteSnapshot(?string $value): static;

    /**
     * Set message
     *
     * @return $this
     *@var string $value
     */
    public function setMessage(string $value): static;

    /**
     * Set unifaun shipping amount
     *
     * @return $this
     *@var float $value
     */
    public function setUnifaunShippingAmount(float $value): static;

    /**
     * Set ingrid shipping amount
     *
     * @return $this
     *@var float $value
     */
    public function setIngridShippingAmount(float $value): static;

    /**
     * Set "is_locked" flag
     *
     * @param bool $value
     * @return LinkInterface
     */
    public function setIsLocked(bool $value): LinkInterface;
}
