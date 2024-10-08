<?php declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Interface for Merchant Push Notification data model
 */
interface MerchantNotificationInterface extends ContainerInterface
{
    /**
     * So far this is the only event type,
     *  Qliro may add more event types in the future
     */
    const EVENT_TYPE_SHIPPING_PROVIDER_UPDATE = 'SHIPPING_PROVIDER_UPDATE';

    /**
     * Use strtolower() when doing comparisons on provider string
     */
    const PROVIDER_NSHIFT = 'unifaun';

    const PROVIDER_INGRID = 'ingrid';

    /**
     * Qliro Order ID
     *
     * @return int
     */
    public function getOrderId(): ?int;

    /**
     * Qliro Order ID
     *
     * @param int $qliroOrderId
     * @return self
     */
    public function setOrderId(int $qliroOrderId): self;

    /**
     * @return string
     */
    public function getMerchantReference(): string;

    /**
     * @param string $ref
     * @return self
     */
    public function setMerchantReference(string $ref): self;

    /**
     * @param boolean $isSuccess
     * @return self
     */
    public function setIsSuccess(bool $isSuccess): self;

    /**
     * @return boolean
     */
    public function getIsSuccess(): bool;

    /**
     * @return string|null
     */
    public function getErrorMessage(): ?string;

    /**
     * @param string $value
     * @return self
     */
    public function setErrorMessage(string $value): self;

    /**
     * @return string
     */
    public function getTimeStamp(): string;

    /**
     * @param string $timeStamp
     * @return self
     */
    public function setTimeStamp(string $timeStamp): self;

    /**
     * @param string $eventType
     * @return self
     */
    public function setEventType(string $eventType): self;

    /**
     * @return string
     */
    public function getEventType(): string;

    /**
     * @param string $provider
     * @return self
     */
    public function setProvider(string $provider): self;

    /**
     * @return string
     */
    public function getProvider(): string;

    /**
     * @param array|null $payload
     * @return self
     */
    public function setPayload(?array $payload): self;

    /**
     * @return array
     */
    public function getPayload(): array;
}
