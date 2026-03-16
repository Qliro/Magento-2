<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Api\Data;

/**
 * QliroOne Order Update Request interface
 *
 * @api
 */
interface QliroOrderUpdateRequestInterface
{
    /**
     * @return \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[]
     */
    public function getOrderItems();

    /**
     * @return string
     */
    public function getAvailableShippingMethods();

    /**
     * @return \Qliro\QliroOne\Api\Data\QliroOrderShippingConfigInterface
     */
    public function getShippingConfiguration();

    /**
     * @return bool
     */
    public function getRequireIdentityVerification();

    /**
     * @param \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[] $value
     * @return $this
     */
    public function setOrderItems($value);

    /**
     * @param array $value
     * @return $this
     */
    public function setAvailableShippingMethods($value);

    /**
     * @param \Qliro\QliroOne\Api\Data\QliroOrderShippingConfigInterface $value
     * @return $this
     */
    public function setShippingConfiguration($value);

    /**
     * @param bool $value
     * @return $this
     */
    public function setRequireIdentityVerification($value);
}
