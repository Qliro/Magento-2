<?php declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Interface for Merchant Saved Credit Card Notification Response data model
 * @link https://developers.qliro.com/docs/qliro-one/get-started/notifications-checkout#merchant-saved-credit-card
 */
interface MerchantSavedCreditCardResponseInterface extends ContainerInterface
{
    const CALLBACK_RESPONSE = 'CallbackResponse';

    const RESPONSE_NOTIFICATIONS_DISABLED = 'Notifications disabled';
    const RESPONSE_AUTHENTICATE_ERROR = 'Authenticate error';
    const RESPONSE_RECEIVED = 'received';
    const RESPONSE_ORDER_NOT_FOUND = 'Order not found';
    const RESPONSE_CRITICAL_ERROR = 'Critical error';
    const RESPONSE_ORDER_PENDING = 'Order creation pending';

    /**
     * @return string
     */
    public function getCallbackResponse(): string;

    /**
     * @param string $value
     * @return $this
     */
    public function setCallbackResponse(string $value): self;

    /**
     * @return int
     */
    public function getCallbackResponseCode(): int;

    /**
     * @param int $code
     * @return $this
     */
    public function setCallbackResponseCode(int $code): self;
}
