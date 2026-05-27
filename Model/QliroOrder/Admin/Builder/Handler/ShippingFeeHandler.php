<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler;

use Magento\Sales\Api\Data\OrderInterface;
use Qliro\QliroOne\Api\Admin\Builder\OrderItemHandlerInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Model\Formatter\PriceFormatter;

/**
 * Shipping Fee Handler class for order items builder
 */
class ShippingFeeHandler implements OrderItemHandlerInterface
{
    const string MERCHANT_REFERENCE_CODE_FIELD = 'qliro_shipping_merchant_ref';

    /**
     * Class constructor
     *
     * @param PriceFormatter $priceFormatter
     */
    public function __construct(
        private readonly PriceFormatter $priceFormatter
    ) {
    }

    /**
     * @inHeirtDoc
     */
    public function handle(array $orderItems, OrderInterface $order): array
    {
        // @todo Handle invoiced and refunded shipping
        if (!$order->getFirstCaptureFlag()) {
            return $orderItems;
        }

        $paymentAdditionalInfo = $order->getPayment()->getAdditionalInformation();
        $merchantReference = $paymentAdditionalInfo[self::MERCHANT_REFERENCE_CODE_FIELD] ?? false;

        $inclTax = (float)$order->getShippingInclTax() - $order->getShippingDiscountAmount();
        $exclTax = $inclTax - $order->getShippingTaxAmount();

        $formattedInclAmount = $this->priceFormatter->format($inclTax);
        $formattedExclAmount = $this->priceFormatter->format($exclTax);

        if ($merchantReference) {
            $orderItems[] = [
                'MerchantReference'  => $merchantReference,
                'Description'        => $merchantReference,
                'Type'               => QliroOrderItemInterface::TYPE_SHIPPING,
                'Quantity'           => 1,
                'PricePerItemIncVat' => $formattedInclAmount,
                'PricePerItemExVat'  => $formattedExclAmount,
                'Metadata'           => ['qliro' => 'checkout'],
            ];
        }

        return $orderItems;
    }
}
