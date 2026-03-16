<?php declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * QliroOne Shipment Interface. Used in MarkItemsAsShipped requests.
 */
interface QliroShipmentInterface
{
    /**
     * @return ?int
     */
    public function getPaymentTransactionId(): ?int;

    /**
     * @return \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[]
     */
    public function getOrderItems(): array;

    /**
     * @param int $value
     * @return self
     */
    public function setPaymentTransactionId(int $value): self;

    /**
     * @param \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[] $value
     * @return self
     */
    public function setOrderItems(array $value): self;
}
