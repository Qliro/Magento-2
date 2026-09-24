<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Admin;

use Qliro\QliroOne\Api\Data\AdminTransactionResponseInterface;

/**
 * Admin QliroOne Order class
 */
class TransactionResponse implements AdminTransactionResponseInterface
{
    private $paymentTransactionId;
    private $status;
    private $type;
    private $reversalPaymentTransactionId;
    private $reversalPaymentTransactionStatus;

    /**
     * @return int
     */
    public function getPaymentTransactionId()
    {
        return $this->paymentTransactionId;
    }

    /**
     * @return string
     */
    public function getStatus()
    {
        return $this->status;
    }

    /**
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * @return int
     */
    public function getReversalPaymentTransactionId()
    {
        return $this->reversalPaymentTransactionId;
    }

    /**
     * @return string
     */
    public function getReversalPaymentTransactionStatus()
    {
        return $this->reversalPaymentTransactionStatus;
    }

    /**
     * @param int $value
     * @return $this
     */
    public function setPaymentTransactionId($value)
    {
        $this->paymentTransactionId = $value;
        return $this;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function setStatus($value)
    {
        $this->status = $value;
        return $this;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function setType($value)
    {
        $this->type = $value;
        return $this;
    }

    /**
     * @param int $value
     * @return $this
     */
    public function setReversalPaymentTransactionId($value)
    {
        $this->reversalPaymentTransactionId = $value;
        return $this;
    }

    /**
     * @param string $value
     * @return $this
     */
    public function setReversalPaymentTransactionStatus($value)
    {
        $this->reversalPaymentTransactionStatus = $value;
        return $this;
    }
}
