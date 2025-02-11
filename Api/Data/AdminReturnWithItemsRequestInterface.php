<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Api\Data;

/**
 * Admin Return With Items Request interface
 */
interface AdminReturnWithItemsRequestInterface extends ContainerInterface
{
    /**
     * @return string
     */
    public function getMerchantApiKey();

    /**
     * @return int
     */
    public function getPaymentReference();

    /**
     * @return string
     */
    public function getRequestId();

    /**
     * @return string
     */
    public function getCurrency();

    /**
     * @return \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[]
     */
    public function getOrderItems();

    /**
     * @return \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[]
     */
    public function getFees();

    /**
     * @param string $value
     * @return $this
     */
    public function setMerchantApiKey($value);

    /**
     * @param int $value
     * @return $this
     */
    public function setPaymentReference($value);

    /**
     * @param string $value
     * @return $this
     */
    public function setRequestId($value);

    /**
     * @param string $value
     * @return $this
     */
    public function setCurrency($value);

    /**
     * @param \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[] $value
     * @return $this
     */
    public function setOrderItems($value);

    /**
     * @param \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[] $value
     * @return $this
     */
    public function setFees($value);

    /**
     * @param int $value
     * @return $this
     */
    public function setOrderId(int $value);

    /**
     * @return int
     */
    public function getOrderId(): int;

    /**
     * @param array $value
     * @return $this
     */
    public function setReturns(array $value);

    /**
     * @return array
     */
    public function getReturns(): array;

    /**
     * @param int $value
     * @return $this
     */
    public function setPaymentTransactionId(int$value);

    /**
     * @return int
     */
    public function getPaymentTransactionId(): int;
}
