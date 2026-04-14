<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder;

use Qliro\QliroOne\Api\Data\QliroOrderUpdateRequestInterface;

/**
 * QliroOne Order Update Request concrete implementation
 */
class UpdateRequest implements QliroOrderUpdateRequestInterface
{
    /**
     * @var \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[]
     */
    private $orderItems;

    /**
     * @var string
     */
    private $availableShippingMethods;

    /**
     * @var \Qliro\QliroOne\Api\Data\QliroOrderShippingConfigInterface
     */
    private $shippingConfiguration;

    /**
     * @var string
     */
    private $requireIdentityVerification;

    /**
     * @var bool|null
     */
    private $digital;

    /**
     * @return \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[]
     */
    public function getOrderItems()
    {
        return $this->orderItems;
    }

    /**
     * @return string
     */
    public function getAvailableShippingMethods()
    {
        return $this->availableShippingMethods;
    }

    /**
     * @return bool
     */
    public function getRequireIdentityVerification()
    {
        return $this->requireIdentityVerification;
    }

    /**
     * @param \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[] $value
     * @return $this
     */
    public function setOrderItems($value)
    {
        $this->orderItems = $value;

        return $this;
    }

    /**
     * @param array $value
     * @return $this
     */
    public function setAvailableShippingMethods($value)
    {
        $this->availableShippingMethods = $value;

        return $this;
    }

    /**
     * @param bool $value
     * @return $this
     */
    public function setRequireIdentityVerification($value)
    {
        $this->requireIdentityVerification = $value;

        return $this;
    }

    /**
     * Getter.
     *
     * @return \Qliro\QliroOne\Api\Data\QliroOrderShippingConfigInterface
     */
    public function getShippingConfiguration()
    {
        return $this->shippingConfiguration;
    }

    /**
     * @param \Qliro\QliroOne\Api\Data\QliroOrderShippingConfigInterface $shippingConfiguration
     * @return CreateRequest
     */
    public function setShippingConfiguration($shippingConfiguration)
    {
        $this->shippingConfiguration = $shippingConfiguration;

        return $this;
    }

    /**
     * @return bool|null
     */
    public function getDigital()
    {
        return $this->digital;
    }

    /**
     * @param bool $value
     * @return $this
     */
    public function setDigital($value)
    {
        $this->digital = $value;

        return $this;
    }
}