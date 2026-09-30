<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Admin Update Merchant Reference Request interface
 */
interface AdminUpdateMerchantReferenceRequestInterface extends ContainerInterface
{
    /**
     * @return string
     */
    public function getMerchantApiKey();

    /**
     * @return int
     */
    public function getOrderId();

    /**
     * @return string
     */
    public function getRequestId();

    /**
     * @return string
     */
    public function getNewMerchantReference();

    /**
     * @param string $value
     * @return $this
     */
    public function setMerchantApiKey($value);

    /**
     * @param int $value
     * @return $this
     */
    public function setOrderId($value);

    /**
     * @param string $value
     * @return $this
     */
    public function setRequestId($value);

    /**
     * @param string $value
     * @return $this
     */
    public function setNewMerchantReference($value);
}
