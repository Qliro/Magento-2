<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * An update that would write to a quote Qliro has already validated the order for
 *
 * Raised instead of writing, so the refusal is decided where the write is: whether a shipping
 * update changes anything is only known after the store's own observers have had the payload, and
 * a caller guessing at it beforehand either refuses a call that writes nothing, which reached the
 * buyer as an error dialog, or lets one through that writes.
 */
class QuoteValidatedException extends LocalizedException
{
}
