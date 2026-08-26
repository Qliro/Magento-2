<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

// @codingStandardsIgnoreFile
// phpcs:ignoreFile

namespace Qliro\QliroOne\Model\Exception;

/**
 * Terminal Exception class
 */
class TerminalException extends \Exception
{
    /**
     * Qliro's own error code from the response body, when the failure carried one
     *
     * @var string|null
     */
    private $qliroErrorCode;

    /**
     * Qliro's own error message from the response body, when the failure carried one
     *
     * @var string|null
     */
    private $qliroErrorMessage;

    /**
     * Attach the error Qliro reported, so callers can act on the reason instead of only knowing
     * that something failed. Service wraps every API failure in this exception, so without this
     * the code and message were lost here and the caller could only report "the request failed".
     *
     * @param string|null $code
     * @param string|null $message
     * @return $this
     */
    public function setQliroError($code, $message = null)
    {
        $this->qliroErrorCode = $code;
        $this->qliroErrorMessage = $message;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getQliroErrorCode()
    {
        return $this->qliroErrorCode;
    }

    /**
     * @return string|null
     */
    public function getQliroErrorMessage()
    {
        return $this->qliroErrorMessage;
    }
}
