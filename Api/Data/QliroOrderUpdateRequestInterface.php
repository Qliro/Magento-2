<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

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
    public function getOrderItems(): array;

    /**
     * @return array|null
     */
    public function getAvailableShippingMethods(): ?array;

    /**
     * @return \Qliro\QliroOne\Api\Data\QliroOrderShippingConfigInterface|null
     */
    public function getShippingConfiguration(): ?QliroOrderShippingConfigInterface;

    /**
     * @return bool|null
     */
    public function getRequireIdentityVerification(): ?bool;

    /**
     * @param mixed $value
     * @return static
     */
    public function setOrderItems(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setAvailableShippingMethods(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setShippingConfiguration(mixed $value): static;

    /**
     * @param mixed $value
     * @return static
     */
    public function setRequireIdentityVerification(mixed $value): static;
}
