<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Notification;

use Qliro\QliroOne\Api\Data\QliroOrderManagementStatusResponseInterface;

/**
 * Management Status Push Response class
 */
class QliroOrderManagementStatusResponse implements QliroOrderManagementStatusResponseInterface
{
    /**
     * @var string
     */
    private $callbackResponse;

    /**
     * @var string|null
     */
    private $message;

    /**
     * @return string
     */
    public function getCallbackResponse()
    {
        return $this->callbackResponse;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function setCallbackResponse($value)
    {
        $this->callbackResponse = $value;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * @param string|null $value
     * @return $this
     */
    public function setMessage($value)
    {
        $this->message = $value;

        return $this;
    }
}
