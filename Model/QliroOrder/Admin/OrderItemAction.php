<?php
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin;

use Qliro\QliroOne\Api\Data\AdminOrderItemActionInterface;
use Qliro\QliroOne\Model\QliroOrder\Item;

class OrderItemAction extends Item implements AdminOrderItemActionInterface
{
    private string $actionType = '';
    private ?int $paymentTransactionId = null;

    public function getActionType(): string
    {
        return $this->actionType;
    }

    public function setActionType(string $value): static
    {
        $this->actionType = $value;
        return $this;
    }

    public function getPaymentTransactionId(): ?int
    {
        return $this->paymentTransactionId;
    }

    public function setPaymentTransactionId(?int $value): static
    {
        $this->paymentTransactionId = $value;
        return $this;
    }
}
