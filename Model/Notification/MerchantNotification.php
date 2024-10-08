<?php declare(strict_types=1);

namespace Qliro\QliroOne\Model\Notification;

use Qliro\QliroOne\Api\Data\MerchantNotificationInterface;

/**
 * Data Model for Merchant Notification
 */
class MerchantNotification implements MerchantNotificationInterface
{
    private ?int $orderId = null;

    private string $merchantReference = '';

    private bool $isSuccess = false;

    private ?string $errorMessage = null;

    private string $timeStamp = '';

    private string $eventType = self::EVENT_TYPE_SHIPPING_PROVIDER_UPDATE;

    private string $provider = '';

    private array $payload = [];

    /**
     * @inheritDoc
     */
    public function getOrderId(): ?int
    {
        return $this->orderId;
    }

    /**
     * @inheritDoc
     */
    public function setOrderId(int $orderId): self
    {
        $this->orderId = $orderId;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getMerchantReference(): string
    {
        return $this->merchantReference;
    }

    /**
     * @inheritDoc
     */
    public function setMerchantReference(string $ref): MerchantNotificationInterface
    {
        $this->merchantReference = $ref;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getIsSuccess(): bool
    {
        return $this->isSuccess;
    }

    /**
     * @inheritDoc
     */
    public function setIsSuccess($isSuccess): self
    {
        $this->isSuccess = $isSuccess;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    /**
     * @inheritDoc
     */
    public function setErrorMessage($errorMessage): self
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getTimeStamp(): string
    {
        return $this->timeStamp;
    }

    /**
     * @inheritDoc
     */
    public function setTimeStamp($timeStamp): self
    {
        $this->timeStamp = $timeStamp;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getEventType(): string
    {
        return $this->eventType;
    }

    /**
     * @inheritDoc
     */
    public function setEventType($eventType): self
    {
        $this->eventType = $eventType;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getProvider(): string
    {
        return $this->provider;
    }

    /**
     * @inheritDoc
     */
    public function setProvider($provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getPayload(): array
    {
        return $this->payload;
    }

    /**
     * @inheritDoc
     */
    public function setPayload(?array $payload): self
    {
        if (is_array($payload)) {
            $this->payload = $payload;
        }

        return $this;
    }
}
