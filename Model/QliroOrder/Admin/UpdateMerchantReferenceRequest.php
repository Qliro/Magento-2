<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin;

use Qliro\QliroOne\Api\Data\AdminUpdateMerchantReferenceRequestInterface;

/**
 * Update QliroOne Order merchant reference request class
 */
class UpdateMerchantReferenceRequest implements AdminUpdateMerchantReferenceRequestInterface
{
    private string $newMerchantReference = '';
    private string $merchantApiKey = '';
    private int $orderId = 0;
    private string $requestId = '';

    public function getMerchantApiKey(): string
    {
        return $this->merchantApiKey;
    }

    public function setMerchantApiKey($merchantApiKey): static
    {
        $this->merchantApiKey = (string)$merchantApiKey;
        return $this;
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function setOrderId($orderId): static
    {
        $this->orderId = (int)$orderId;
        return $this;
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function setRequestId($requestId): static
    {
        $this->requestId = (string)$requestId;
        return $this;
    }

    public function getNewMerchantReference(): string
    {
        return $this->newMerchantReference;
    }

    public function setNewMerchantReference($value): static
    {
        $this->newMerchantReference = (string)$value;
        return $this;
    }
}
