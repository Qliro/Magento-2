<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Api\Client\Exception;

/**
 * Thrown by the Qliro Merchant API client when Qliro responds with ORDER_EXPIRED.
 *
 * Qliro order sessions expire after 90 minutes and the order itself is no longer
 * mutable after 48 hours, after which it ends up in a "refused" state and Qliro
 * will not accept further updates. Callers should catch this specifically and
 * recreate a fresh Qliro order from the same Magento quote (with a NEW unique
 * merchant reference — Qliro does not allow reusing an order reference).
 */
class OrderExpiredException extends MerchantApiException
{
}
