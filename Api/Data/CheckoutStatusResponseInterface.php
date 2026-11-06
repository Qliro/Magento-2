<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Update Shipping Methods notification callback response interface
 */
interface CheckoutStatusResponseInterface extends ContainerInterface
{
    const CALLBACK_RESPONSE = 'CallbackResponse';

    const RESPONSE_NOTIFICATIONS_DISABLED = 'Notifications disabled';
    const RESPONSE_AUTHENTICATE_ERROR = 'Authenticate error';
    const RESPONSE_RECEIVED = 'received';
    const RESPONSE_ORDER_NOT_FOUND = 'Order not found';
    const RESPONSE_ORDER_PENDING = 'Order creation pending';
    const RESPONSE_LINK_NOT_FOUND = 'Link not found';
    const RESPONSE_PLACEMENT_FAILED = 'Order placement failed';
    const RESPONSE_STATUS_NOT_APPLIED = 'Order status could not be applied';

    /**
     * @return string
     */
    public function getCallbackResponse();

    /**
     * @param string $value
     * @return $this
     */
    public function setCallbackResponse($value);

    /**
     * @param int $code
     * @return $this
     */
    public function setCallbackResponseCode($code);

    /**
     * @return int
     */
    public function getCallbackResponseCode();
}
