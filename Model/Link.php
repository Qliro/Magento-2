<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model;

use Magento\Framework\Model\AbstractModel;
use Qliro\QliroOne\Api\Data\LinkInterface;

/**
 * Link record model class
 */
class Link extends AbstractModel implements LinkInterface
{
    /**
     * Initialize a resource model
     */
    protected function _construct(): void
    {
        $this->_init(ResourceModel\Link::class);
    }

    /**
     * @inheritdoc
     */
    public function getId(): mixed
    {
        return $this->getData(self::FIELD_ID);
    }

    /**
     * @inheritdoc
     */
    public function getIsActive(): int
    {
        return $this->getData(self::FIELD_IS_ACTIVE);
    }

    /**
     * @inheritdoc
     */
    public function getReference(): string
    {
        return $this->getData(self::FIELD_REFERENCE);
    }

    /**
     * @inheritdoc
     */
    public function getQuoteId(): ?int
    {
        $value = $this->getData(self::FIELD_QUOTE_ID);
        return $value !== null ? (int)$value : null;
    }

    /**
     * @inheritdoc
     */
    public function getQliroOrderId(): ?int
    {
        $value = $this->getData(self::FIELD_QLIRO_ORDER_ID);
        return $value !== null ? (int)$value : null;
    }

    /**
     * @inheritdoc
     */
    public function getQliroOrderStatus(): ?string
    {
        return $this->getData(self::FIELD_QLIRO_ORDER_STATUS);
    }

    /**
     * @inheritdoc
     */
    public function getOrderId(): ?int
    {
        $value = $this->getData(self::FIELD_ORDER_ID);
        return $value !== null ? (int)$value : null;
    }

    /**
     * @inheritdoc
     */
    public function getRemoteIp(): string
    {
        return $this->getData(self::FIELD_REMOTE_IP);
    }

    /**
     * @inheritdoc
     */
    public function getCreatedAt(): string
    {
        return $this->getData(self::FIELD_CREATED_AT);
    }

    /**
     * @inheritdoc
     */
    public function getUpdatedAt(): string
    {
        return $this->getData(self::FIELD_UPDATED_AT);
    }

    /**
     * @inheritdoc
     */
    public function getPlacedAt(): string
    {
        return $this->getData(self::FIELD_PLACED_AT);
    }

    /**
     * @inheritdoc
     */
    public function getQuoteSnapshot(): string
    {

        return $this->getData(self::FIELD_QUOTE_SNAPSHOT);
    }

    /**
     * @inheritdoc
     */
    public function getMessage(): string
    {
        return $this->getData(self::FIELD_MESSAGE);
    }

    /**
     * @inheritdoc
     */
    public function getUnifaunShippingAmount(): ?float
    {
        $value = $this->getData(self::FIELD_UNIFAUN_SHIPPING_AMOUNT);
        return $value !== null ? (float)$value : null;
    }

    /**
     * @inheritdoc
     */
    public function getIngridShippingAmount(): ?float
    {
        $value = $this->getData(self::FIELD_INGRID_SHIPPING_AMOUNT);
        return $value !== null ? (float)$value : null;
    }

    /**
     * @inheritDoc
     */
    public function getIsLocked(): bool
    {
        return (bool)$this->getData(self::FIELD_IS_LOCKED);
    }

    /**
     * @inheritdoc
     */
    public function setId(mixed $value): static
    {
        return $this->setData(self::FIELD_ID, $value);
    }

    /**
     * @inheritdoc
     */
    public function setIsActive(int|bool $value): static
    {
        return $this->setData(self::FIELD_IS_ACTIVE, $value);
    }

    /**
     * @inheritdoc
     */
    public function setReference(string $value): static
    {
        return $this->setData(self::FIELD_REFERENCE, $value);
    }

    /**
     * @inheritdoc
     */
    public function setQuoteId(int|string $value): static
    {
        return $this->setData(self::FIELD_QUOTE_ID, $value);
    }

    /**
     * @inheritdoc
     */
    public function setQliroOrderId(int|string|null $value): static
    {
        return $this->setData(self::FIELD_QLIRO_ORDER_ID, $value !== null ? (int)$value : null);
    }

    /**
     * @inheritdoc
     */
    public function setQliroOrderStatus(string $value): static
    {
        return $this->setData(self::FIELD_QLIRO_ORDER_STATUS, $value);
    }

    /**
     * @inheritdoc
     */
    public function setOrderId(int|string $value): static
    {
        return $this->setData(self::FIELD_ORDER_ID, $value);
    }

    /**
     * @inheritdoc
     */
    public function setRemoteIp(string $value): static
    {
        return $this->setData(self::FIELD_REMOTE_IP, $value);
    }

    /**
     * @inheritdoc
     */
    public function setCreatedAt(string $value): static
    {
        return $this->setData(self::FIELD_CREATED_AT, $value);
    }

    /**
     * @inheritdoc
     */
    public function setUpdatedAt(string $value): static
    {
        return $this->setData(self::FIELD_UPDATED_AT, $value);
    }

    /**
     * @inheritdoc
     */
    public function setPlacedAt(string $value): static
    {
        return $this->setData(self::FIELD_PLACED_AT, $value);
    }

    /**
     * @inheritdoc
     */
    public function setQuoteSnapshot(?string $value): static
    {
        return $this->setData(self::FIELD_QUOTE_SNAPSHOT, $value);
    }

    /**
     * @inheritdoc
     */
    public function setMessage(string $value): static
    {
        return $this->setData(self::FIELD_MESSAGE, $value);
    }

    /**
     * @inheritdoc
     */
    public function setUnifaunShippingAmount(float $value): static
    {
        return $this->setData(self::FIELD_UNIFAUN_SHIPPING_AMOUNT, $value);
    }

    /**
     * @inheritdoc
     */
    public function setIngridShippingAmount(?float $value): static
    {
        return $this->setData(self::FIELD_INGRID_SHIPPING_AMOUNT, $value);
    }

    /**
     * @inheritDoc
     */
    public function setIsLocked(bool $value): static
    {
        return $this->setData(self::FIELD_IS_LOCKED, $value);
    }
}
