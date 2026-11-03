<?php declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder;

use Qliro\QliroOne\Api\Data\QliroShipmentInterface;

/**
 * Qliro Order Shipment data model
 */
class Shipment implements QliroShipmentInterface
{
    /**
     * @var int|null
     */
    private ?int $paymentTransactionId = null;

    /**
     * @var \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[]
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
    public function getOrderItems(): array
    {
        return $this->orderItems;
    }

    /**
     * @inheritDoc
     */
    public function setPaymentTransactionId(int $value): QliroShipmentInterface
    {
        $this->paymentTransactionId = $value;
        return $this;
    }

    /**
     * @param \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[] $value
     * @return \Qliro\QliroOne\Api\Data\QliroShipmentInterface
     */
    public function setOrderItems(array $value): QliroShipmentInterface
    {
        $this->orderItems = $value;
        return $this;
    }
}