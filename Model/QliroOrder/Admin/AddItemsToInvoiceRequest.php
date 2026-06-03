<?php

/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Admin;

use Qliro\QliroOne\Api\Data\AdminAddItemsToInvoiceRequestInterface;
use Qliro\QliroOne\Api\Data\AdminAdditionsInterface;

class AddItemsToInvoiceRequest implements AdminAddItemsToInvoiceRequestInterface
{
    /**
     * @var string
     */
    private string $requestId;

    /**
     * @var string
     */
    private string $merchantApiKey;

    /**
     * @var int
     */
    private int $orderId;

    /**
     * @var string
     */
    private string $currency;

    /**
     * @var array|AdminAdditionsInterface[]
     */
    private array $additions;

    /**
     * @inheritDoc
     */
    public function getRequestId(): string
    {
        return $this->requestId;
    }

    /**
     * @inheritDoc
     */
    public function setRequestId(string $value): AdminAddItemsToInvoiceRequestInterface
    {
        $this->requestId = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getMerchantApiKey(): string
    {
        return $this->merchantApiKey;
    }

    /**
     * @inheritDoc
     */
    public function setMerchantApiKey(string $value): AdminAddItemsToInvoiceRequestInterface
    {
        $this->merchantApiKey = $value;

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
    public function setOrderId(int $value): AdminAddItemsToInvoiceRequestInterface
    {
        $this->orderId = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * @inheritDoc
     */
    public function setCurrency(string $value): AdminAddItemsToInvoiceRequestInterface
    {
        $this->currency = $value;

        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getAdditions(): array
    {
        return $this->additions;
    }

    /**
     * @inheritDoc
     */
    public function setAdditions(array $value): AdminAddItemsToInvoiceRequestInterface
    {
        $this->additions = $value;

        return $this;
    }
}
