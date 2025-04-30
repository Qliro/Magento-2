<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Admin;

use Qliro\QliroOne\Api\Data\AdminReturnWithItemsRequestInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Model\ContainerMapper;

/**
 * Return With Items Request class
 */
class ReturnWithItemsRequest implements AdminReturnWithItemsRequestInterface
{
    /**
     * @var string
     */
    private $merchantApiKey;

    /**
     * @var int
     */
    private $paymentReference;

    /**
     * @var string
     */
    private $requestId;

    /**
     * @var string
     */
    private $currency;

    /**
     * @var QliroOrderItemInterface[]
     */
    private $orderItems;

    /**
     * @var QliroOrderItemInterface[]
     */
    private $fees;

    /**
     * @var QliroOrderItemInterface[]
     */
    private $discounts;

    /**
     * @var int
     */
    private int $orderId;

    /**
     * @var int
     */
    private int $paymentTransactionId;

    /**
     * @var array
     */
    private array $returns = [];

    /**
     * @var ContainerMapper
     */
    private $containerMapper;

    /**
     * @param ContainerMapper $containerMapper
     */
    public function __construct(
        ContainerMapper $containerMapper
    )
    {
        $this->containerMapper = $containerMapper;
    }

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
     * @return ReturnWithItemsRequest
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
    public function getPaymentReference()
    {
        return $this->paymentReference;
    }

    /**
     * @param int $paymentReference
     * @return ReturnWithItemsRequest
     */
    public function setPaymentReference($paymentReference)
    {
        $this->paymentReference = $paymentReference;

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
     * @return ReturnWithItemsRequest
     */
    public function setRequestId($requestId)
    {
        $this->requestId = $requestId;

        return $this;
    }

    /**
     * Getter.
     *
     * @return string
     */
    public function getCurrency()
    {
        return $this->currency;
    }

    /**
     * @param string $currency
     * @return ReturnWithItemsRequest
     */
    public function setCurrency($currency)
    {
        $this->currency = $currency;

        return $this;
    }

    /**
     * Getter.
     *
     * @return QliroOrderItemInterface[]|null
     */
    public function getOrderItems()
    {
        return null;
    }

    /**
     * @param QliroOrderItemInterface[] $orderItems
     * @return ReturnWithItemsRequest
     */
    public function setOrderItems($orderItems)
    {
        if (!count($orderItems)) {
            return $this;
        }

        // Convert positive discount numbers to negative
        foreach ($orderItems as $key => $orderItem) {
            if ($orderItem->getType() === QliroOrderItemInterface::TYPE_DISCOUNT) {
                $orderItem->setPricePerItemExVat(-abs($orderItem->getPricePerItemExVat()));
                $orderItem->setPricePerItemIncVat(-abs($orderItem->getPricePerItemIncVat()));
            }

        }

        $this->orderItems = $orderItems;

        return $this;
    }

    /**
     * Getter.
     *
     * @return QliroOrderItemInterface[]|null
     */
    public function getFees()
    {
        return null;
    }

    /**
     * @param QliroOrderItemInterface[] $fees
     * @return ReturnWithItemsRequest
     */
    public function setFees($fees)
    {
        $this->fees = $fees;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function setOrderId(int $value)
    {
        $this->orderId = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getOrderId(): int
    {
        return $this->orderId;
    }

    /**
     * @inheritDoc
     */
    public function setReturns(array $value)
    {
        $this->returns = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getReturns(): array
    {
        if ($this->paymentTransactionId) {
            $this->returns['PaymentTransactionId'] = $this->paymentTransactionId;
        }

        if (count($this->orderItems)) {
            $orderItems = [];
            foreach ($this->orderItems as $orderItem) {
                $innerItem = $this->containerMapper->toArray($orderItem);
                if (!count($innerItem)){
                    continue;
                }

                $orderItems[] = $innerItem;
            }

            if (count($orderItems)) {
                $this->returns['OrderItems'] = $orderItems;
            }
        }

        if (count($this->fees)) {
            $fees = [];
            foreach ($this->fees as $fee) {
                $innerItem = $this->containerMapper->toArray($fee);
                if (!count($innerItem)){
                    continue;
                }

                $fees[] = $innerItem;
            }

            if (count($fees)) {
                $this->returns['Fees'] = $fees;
            }
        }

        if (count($this->discounts)) {
            $discounts = [];
            foreach ($this->discounts as $discount) {
                $innerItem = $this->containerMapper->toArray($discount);
                if (!count($innerItem)){
                    continue;
                }

                $discounts[] = $innerItem;
            }

            if (count($discounts)) {
                $this->returns['Discounts'] = $discounts;
            }
        }

        return $this->returns;
    }

    /**
     * @inheritDoc
     */
    public function setPaymentTransactionId(int $value)
    {
        $this->paymentTransactionId = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getPaymentTransactionId(): int
    {
        return $this->paymentTransactionId;
    }

    /**
     * @inheritDoc
     */
    public function getDiscounts()
    {
        return $this->discounts;
    }

    /**
     * @inheritDoc
     */
    public function setDiscounts($discounts)
    {
        $this->discounts = $discounts;

        return $this;
    }
}
