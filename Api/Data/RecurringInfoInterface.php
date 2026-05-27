<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * Recurring Info interface
 *
 * @api
 *
 * @method int getId()
 * @method static setId(int $id)
 * @method static setFrequencyOption(string $recurringFrequency)
 * @method string getFrequencyOption()
 * @method static setOriginalOrderId(int $orderId)
 * @method int getOriginalOrderId()
 * @method static setPaymentMethod(string $paymentMethod)
 * @method string getPaymentMethod()
 * @method static setSavedCreditCardId(string $id)
 * @method string|null getSavedCreditCardId()
 * @method static setNextOrderDate(string|null $nextOrderDate)
 * @method string|null getNextOrderDate()
 * @method static setCanceledDate(string $canceledDate)
 * @method string|null getCanceledDate()
 * @method string|null getPersonalNumber()
 */
interface RecurringInfoInterface
{
}
