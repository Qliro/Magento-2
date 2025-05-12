<?php declare(strict_types=1);

namespace Qliro\QliroOne\Api\Data;

/**
 * @method int getId()
 * @method self setId(int $id)
 * @method self setFrequencyOption(string $recurringFrequency)
 * @method string getFrequencyOption()
 * @method self setOriginalOrderId(int $orderId)
 * @method int getOriginalOrderId()
 * @method self setPaymentMethod(string $paymentMethod)
 * @method string getPaymentMethod()
 * @method self setSavedCreditCardId(string $id)
 * @method string|null getSavedCreditCardId()
 * @method self setNextOrderDate(string|null $nextOrderDate)
 * @method string|null getNextOrderDate()
 * @method self setCanceledDate(string $canceledDate)
 * @method string|null getCanceledDate()
 * @method string|null getPersonalNumber()
 */
interface RecurringInfoInterface
{
}
