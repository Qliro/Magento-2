<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Client;

use Qliro\QliroOne\Api\Data\QliroOrderCreateRequestInterface;
use Qliro\QliroOne\Api\Data\QliroOrderUpdateRequestInterface;

/**
 * Merchant API client interface
 *
 * @api
 */
interface MerchantInterface
{
    /**
     * Perform QliroOne order creation
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderCreateRequestInterface $qliroOrderCreateRequest
     * @return int
     */
    public function createOrder(QliroOrderCreateRequestInterface $qliroOrderCreateRequest);

    /**
     * Get QliroOne order by its Qliro Order ID
     *
     * The same fetch serves the checkout page, where the customer is waiting for it, and the
     * callbacks and polls that nobody waits for, so the caller says which timeouts it wants.
     *
     * @param int $qliroOrderId
     * @param string|null $profile Which timeouts to call with, see Config::API_PROFILE_*
     * @return \Qliro\QliroOne\Api\Data\QliroOrderInterface
     */
    public function getOrder($qliroOrderId, $profile = null);

    /**
     * Update QliroOne order
     *
     * @param int $qliroOrderId
     * @param \Qliro\QliroOne\Api\Data\QliroOrderUpdateRequestInterface $qliroOrderUpdateRequest
     * @return void
     */
    public function updateOrder($qliroOrderId, QliroOrderUpdateRequestInterface $qliroOrderUpdateRequest);

    /**
     * Delete a saved card token at Qliro
     *
     * @param string $savedCreditCardId
     * @param int|null $storeId
     * @return bool False when Qliro no longer had the card
     */
    public function removeSavedCreditCard(string $savedCreditCardId, $storeId = null): bool;
}
