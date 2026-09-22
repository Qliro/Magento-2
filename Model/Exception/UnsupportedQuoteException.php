<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * A cart this payment method cannot be asked to take, in words the buyer can act on
 *
 * Thrown where the Qliro order is created, which is the one point every checkout mode passes
 * through, and carried out to the page rather than folded into "the checkout failed to load":
 * the buyer can only fix a cart they are told about.
 */
class UnsupportedQuoteException extends LocalizedException
{
}
