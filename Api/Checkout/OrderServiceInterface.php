<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Checkout;

use Qliro\QliroOne\Model\Exception\TerminalException;

/**
 * Checkout lifecycle operations
 *
 * @api
 */
interface OrderServiceInterface
{
    /**
     * Retrieve the live Qliro order for the current session quote and return it as an array
     *
     * @param bool $allowRecreate
     * @return array
     * @throws TerminalException
     */
    public function getQliroOrder(bool $allowRecreate = true): array;

    /**
     * Handle a CheckoutStatus server-to-server push from Qliro
     *
     * @param array $checkoutStatus
     * @return array
     */
    public function checkoutStatus(array $checkoutStatus): array;

    /**
     * Update quote with received data from the Qliro callback and return available shipping methods
     *
     * @param array $updateContainer
     * @return array
     */
    public function getShippingMethods(array $updateContainer): array;

    /**
     * Validate the Qliro order and apply customer / address data to the quote
     *
     * @param array $validateContainer
     * @return array
     */
    public function validateQliroOrder(array $validateContainer): array;

    /**
     * Update the quote with customer data received from the Qliro frontend callback
     *
     * @param array $customerData
     * @return void
     * @throws \Exception
     */
    public function updateCustomer(array $customerData): void;

    /**
     * Update the selected shipping method on the quote
     *
     * @param string $code
     * @param string|null $secondaryOption
     * @param float|null $price
     * @return bool
     * @throws \Exception
     */
    public function updateShippingMethod(string $code, ?string $secondaryOption = null, ?float $price = null): bool;

    /**
     * Update the shipping price on the quote
     *
     * @param float|null $price
     * @return bool
     * @throws \Exception
     */
    public function updateShippingPrice(?float $price): bool;

    /**
     * Update the fee on the quote
     *
     * @param float $fee
     * @return bool
     * @throws \Exception
     */
    public function updateFee(float $fee): bool;
}
