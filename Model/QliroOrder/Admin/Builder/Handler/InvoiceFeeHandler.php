<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler;

use Magento\Sales\Api\Data\OrderInterface as Order;
use Qliro\QliroOne\Api\Admin\Builder\OrderItemHandlerInterface;

/**
 * Invoice Fee Handler class for order items builder
 */
class InvoiceFeeHandler implements OrderItemHandlerInterface
{
    const string MERCHANT_REFERENCE_CODE_FIELD = 'merchant_reference_code';
    const string MERCHANT_REFERENCE_DESCRIPTION_FIELD = 'merchant_reference_description';

    /**
     * @inHeirtDoc
     */
    public function handle(array $orderItems, Order $order): array
    {
        if (!$order->getFirstCaptureFlag()) {
            return $orderItems;
        }
        $qlirooneFees = $order->getPayment()->getAdditionalInformation('qliroone_fees');
        if (is_array($qlirooneFees)) {
            foreach ($qlirooneFees as $qlirooneFee) {
                $orderItems[] = [
                    'MerchantReference'  => $qlirooneFee['MerchantReference'],
                    'Description'        => $qlirooneFee['Description'],
                    'Type'               => $qlirooneFee['Type'],
                    'Quantity'           => $qlirooneFee['Quantity'],
                    'PricePerItemIncVat' => $qlirooneFee['PricePerItemIncVat'],
                    'PricePerItemExVat'  => $qlirooneFee['PricePerItemExVat'],
                    'Metadata'           => ['qliro' => 'checkout'],
                ];
            }
        }

        return $orderItems;
    }
}
