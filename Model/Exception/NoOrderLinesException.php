<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Exception;

/**
 * A cart with nothing to pay for, so no Qliro order is created and the buyer goes back to the cart
 */
class NoOrderLinesException extends UnsupportedQuoteException
{
}
