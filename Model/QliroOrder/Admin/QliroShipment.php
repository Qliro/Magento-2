<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin;

use Qliro\QliroOne\Api\Data\QliroShipmentInterface;

/**
 * QliroShipment model — DTO used inside MarkItemsAsShipped requests.
 */
class QliroShipment implements QliroShipmentInterface
{
    /**
     * @var int|null
     */
    private ?int $paymentTransactionId = null;

    /**
     * @var array
     */
    private array $orderItems = [];

    /**
     * @inheritDoc
     */
    public function getPaymentTransactionId(): ?int
    {
        return $this->paymentTransactionId;
    }

    /**
     * @inheritDoc
     */
    public function setPaymentTransactionId(int $value): static
    {
        $this->paymentTransactionId = $value;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function getOrderItems(): array
    {
        return $this->orderItems;
    }

    /**
     * @inheritDoc
     */
    public function setOrderItems(array $value): static
    {
        $this->orderItems = $value;
        return $this;
    }
}
