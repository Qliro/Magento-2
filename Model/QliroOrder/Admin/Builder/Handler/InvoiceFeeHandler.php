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
 * Invoice Fee Handler class for order items builder
 */
class InvoiceFeeHandler implements OrderItemHandlerInterface
{
    const MERCHANT_REFERENCE_CODE_FIELD = 'merchant_reference_code';
    const MERCHANT_REFERENCE_DESCRIPTION_FIELD = 'merchant_reference_description';

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
        if (!$order->getFirstCaptureFlag()) {
            return $orderItems;
        }
        $qlirooneFees = $order->getPayment()->getAdditionalInformation('qliroone_fees');
        if (is_array($qlirooneFees)) {
            foreach ($qlirooneFees as $qlirooneFee) {
                $qliroOrderItem = $this->qliroOrderItemFactory->create();
                $qliroOrderItem->setMerchantReference($qlirooneFee['MerchantReference']);
                $qliroOrderItem->setDescription($qlirooneFee['Description']);
                $qliroOrderItem->setType($qlirooneFee['Type']);
                $qliroOrderItem->setQuantity($qlirooneFee['Quantity']);
                $qliroOrderItem->setPricePerItemIncVat($qlirooneFee['PricePerItemIncVat']);
                $qliroOrderItem->setPricePerItemExVat($qlirooneFee['PricePerItemExVat']);
                $qliroOrderItem->setMetadata(['qliro' => 'checkout']);
                $orderItems[] = $qliroOrderItem;
            }
        }

        return $orderItems;
    }
}
