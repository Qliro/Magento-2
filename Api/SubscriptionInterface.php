<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api;

/**
 * Subscription interface
 *
 * @api
 */
interface SubscriptionInterface
{
    /**
     * Add or activate a newsletter subscription for the given email address
     *
     * @param string $email
     * @param int $storeId
     * @return void
     */
    public function addSubscription(string $email, int $storeId): void;
}
