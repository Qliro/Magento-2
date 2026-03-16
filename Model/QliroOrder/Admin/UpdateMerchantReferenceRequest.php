<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Admin;

use Qliro\QliroOne\Api\Data\AdminCancelOrderRequestInterface;
use Qliro\QliroOne\Api\Data\AdminUpdateMerchantReferenceRequestInterface;

/**
 * Update QliroOne Order merchant reference request class
 */
class UpdateMerchantReferenceRequest implements AdminUpdateMerchantReferenceRequestInterface
{
    /**
     * @var string
     */
    private $newMerchantReference;

    /**
     * @var string
     */
    private $merchantApiKey;

    /**
     * @var int
     */
    private $orderId;

    /**
     * @var string
     */
    private $requestId;

    /**
     * Getter.
     *
     * @return string
     */
    public function getMerchantApiKey()
    {
        return $this->merchantApiKey;
    }

    /**
     * @param string $merchantApiKey
     * @return UpdateMerchantReferenceRequest
     */
    public function setMerchantApiKey($merchantApiKey)
    {
        $this->merchantApiKey = $merchantApiKey;

        return $this;
    }

    /**
     * Getter.
     *
     * @return int
     */
    public function getOrderId()
    {
        return $this->orderId;
    }

    /**
     * @param int $orderId
     * @return UpdateMerchantReferenceRequest
     */
    public function setOrderId($orderId)
    {
        $this->orderId = $orderId;

        return $this;
    }

    /**
     * Getter.
     *
     * @return string
     */
    public function getRequestId()
    {
        return $this->requestId;
    }

    /**
     * @param string $requestId
     * @return UpdateMerchantReferenceRequest
     */
    public function setRequestId($requestId)
    {
        $this->requestId = $requestId;

        return $this;
    }

    /**
     * @return string
     */
    public function getNewMerchantReference()
    {
        return $this->newMerchantReference;
    }

    /**
     * @param string $value
     * @return UpdateMerchantReferenceRequest
     */
    public function setNewMerchantReference($value)
    {
        $this->newMerchantReference = $value;

        return $this;
    }
}
