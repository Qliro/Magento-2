<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model;

/**
 * A readable name for the payment method of an order.
 *
 * Qliro states the method as `PaymentMethod.PaymentMethodName`, which is the product
 * (`QLIRO_INVOICE`, `QLIROPAYLATER_INVOICE30`), and `PaymentMethod.PaymentTypeCode`, which is the
 * instrument behind it (`INVOICE`, `MASTERCARD`, and on some orders a bare number). Neither is
 * meant for a person to read and there is no third field that is, so the wording lives here.
 *
 * The strings go through `__()`, so a store translates them like anything else. A method that is
 * not in the table is shown as Qliro named it rather than hidden, which is what keeps a product
 * launched tomorrow from rendering as an empty cell.
 */
class PaymentMethodLabel
{
    /**
     * Keyed by PaymentMethodName. The names are the ones the payment platform knows, taken from
     * the same table its adapters use, so a method missing here is a new one rather than a typo.
     */
    private const LABELS = [
        // Ironman pay later, the six of PLIN-374
        'QLIROPAYLATER_INVOICE14' => 'Invoice, 14 days',
        'QLIROPAYLATER_INVOICE30' => 'Invoice, 30 days',
        'QLIROPAYLATER_INVOICE30_60' => 'Invoice, 30 to 60 days',
        'QLIROPAYLATER_BNPL' => 'Pay later',
        'QLIROPAYLATER_FLEXIBLE_PART_PAYMENT' => 'Part payment, flexible',
        'QLIROPAYLATER_FIXED_PART_PAYMENT' => 'Part payment, fixed',
        // the pay later products these are migrating from
        'QLIRO_INVOICE' => 'Qliro invoice',
        'QLIRO_INVOICE_30' => 'Qliro invoice, 30 days',
        'QLIRO_CAMPAIGN' => 'Qliro campaign',
        'QLIRO_PARTPAYMENT_ACCOUNT' => 'Qliro part payment, account',
        'QLIRO_PARTPAYMENT_FIXED' => 'Qliro part payment, fixed',
        'QLIRO_PAD_INVOICE' => 'Qliro invoice, direct debit',
        // everything else Qliro can route an order to
        'CREDITCARDS' => 'Card',
        'TRUSTLY' => 'Trustly',
        'TRUSTLY_DIRECT' => 'Trustly',
        'VIPPS' => 'Vipps',
        'MOBILEPAY' => 'MobilePay',
        'PAYPAL' => 'PayPal',
        'SWISH' => 'Swish',
        'TWO_INVOICE' => 'Two invoice',
        'IPICCOLO_INVOICE' => 'Invoice',
        'PAYSAFE_INVOICE' => 'Invoice',
        'CASH_ON_DELIVERY' => 'Cash on delivery',
        'FREE' => 'Free',
    ];

    /**
     * @param string|null $paymentMethodName
     * @param string|null $paymentTypeCode
     * @return string
     */
    public function getLabel($paymentMethodName, $paymentTypeCode = null)
    {
        $name = trim((string)$paymentMethodName);

        if ($name === '') {
            // an order stored before the module recorded the name has only the type code left
            $name = trim((string)$paymentTypeCode);
        }

        if ($name === '') {
            return '';
        }

        $label = self::LABELS[strtoupper($name)] ?? null;

        return $label === null ? $name : (string)__($label);
    }

    /**
     * Whether the method is one we have wording for, which is what the order view uses to decide
     * if stating the raw name next to the label adds anything.
     *
     * @param string|null $paymentMethodName
     * @return bool
     */
    public function isKnown($paymentMethodName)
    {
        return isset(self::LABELS[strtoupper(trim((string)$paymentMethodName))]);
    }
}
