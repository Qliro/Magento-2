<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler;

use Qliro\QliroOne\Api\Admin\Builder\OrderItemHandlerInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory;
use Qliro\QliroOne\Helper\Data as QliroHelper;

/**
 * Shipping Fee Handler class for order items builder
 */
class ShippingFeeHandler implements OrderItemHandlerInterface
{
    const MERCHANT_REFERENCE_CODE_FIELD = 'qliro_shipping_merchant_ref';

    /**
     * Class constructor
     *
     * @param QliroOrderItemInterfaceFactory $qliroOrderItemFactory
     * @param QliroHelper $qliroHelper
     */
    public function __construct(
        private readonly QliroOrderItemInterfaceFactory $qliroOrderItemFactory,
        private readonly QliroHelper $qliroHelper
    ) {
    }

    /**
     * Handle specific type of order items and add them to the QliroOne order items list
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[] $orderItems
     * @param \Magento\Sales\Model\Order $order
     * @return \Qliro\QliroOne\Api\Data\QliroOrderItemInterface[]
     */
    public function handle($orderItems, $order)
    {
        // @todo Handle invoiced and refunded shipping
        if (!$order->getFirstCaptureFlag()) {
            return $orderItems;
        }

        $paymentAdditionalInfo = $order->getPayment()->getAdditionalInformation();
        $merchantReference = $paymentAdditionalInfo[self::MERCHANT_REFERENCE_CODE_FIELD] ?? false;

        $inclTax = (float)$order->getShippingInclTax() - $order->getShippingDiscountAmount();
        $exclTax = $inclTax - $order->getShippingTaxAmount();

        $formattedInclAmount = $this->qliroHelper->formatPrice($inclTax);
        $formattedExclAmount = $this->qliroHelper->formatPrice($exclTax);

        if ($merchantReference) {
            /** @var \Qliro\QliroOne\Api\Data\QliroOrderItemInterface $qliroOrderItem */
            $qliroOrderItem = $this->qliroOrderItemFactory->create();

            $qliroOrderItem->setMerchantReference($merchantReference);
            $qliroOrderItem->setDescription($merchantReference);
            $qliroOrderItem->setType(QliroOrderItemInterface::TYPE_SHIPPING);
            $qliroOrderItem->setQuantity(1);
            $qliroOrderItem->setPricePerItemIncVat($formattedInclAmount);
            $qliroOrderItem->setPricePerItemExVat($formattedExclAmount);
            $qliroOrderItem->setMetadata(['qliro' => 'checkout']);

            $orderItems[] = $qliroOrderItem;
        }

        return $orderItems;
    }
}
