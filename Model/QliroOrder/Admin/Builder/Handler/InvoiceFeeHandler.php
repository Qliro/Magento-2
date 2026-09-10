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
use Qliro\QliroOne\Model\QliroOrder\LineVatRate;

/**
 * Invoice Fee Handler class for order items builder
 */
class InvoiceFeeHandler implements OrderItemHandlerInterface
{
    const MERCHANT_REFERENCE_CODE_FIELD = 'merchant_reference_code';
    const MERCHANT_REFERENCE_DESCRIPTION_FIELD = 'merchant_reference_description';

    /**
     * @var \Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory
     */
    private $qliroOrderItemFactory;

    /**
     * @var \Qliro\QliroOne\Helper\Data
     */
    private $qliroHelper;

    /**
     * @var \Qliro\QliroOne\Model\QliroOrder\LineVatRate
     */
    private $lineVatRate;

    /**
     * Inject dependencies
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderItemInterfaceFactory $qliroOrderItemFactory
     * @param \Qliro\QliroOne\Helper\Data $qliroHelper
     * @param \Qliro\QliroOne\Model\QliroOrder\LineVatRate $lineVatRate
     */
    public function __construct(
        QliroOrderItemInterfaceFactory $qliroOrderItemFactory,
        QliroHelper $qliroHelper,
        LineVatRate $lineVatRate
    ) {

        $this->qliroOrderItemFactory = $qliroOrderItemFactory;
        $this->qliroHelper = $qliroHelper;
        $this->lineVatRate = $lineVatRate;
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
                $priceIncVat = (float)$this->qliroHelper->formatPrice($qlirooneFee['PricePerItemIncVat']);
                $priceExVat = (float)$this->qliroHelper->formatPrice($qlirooneFee['PricePerItemExVat']);

                $qliroOrderItem = $this->qliroOrderItemFactory->create();
                $qliroOrderItem->setMerchantReference($qlirooneFee['MerchantReference']);
                $qliroOrderItem->setDescription($qlirooneFee['Description']);
                $qliroOrderItem->setType($qlirooneFee['Type']);
                $qliroOrderItem->setQuantity($qlirooneFee['Quantity']);
                $qliroOrderItem->setPricePerItemIncVat($priceIncVat);
                $qliroOrderItem->setPricePerItemExVat($priceExVat);
                $qliroOrderItem->setVatRate($this->getVatRate($qlirooneFee));
                $qliroOrderItem->setMetadata(['qliro' => 'checkout']);
                $orderItems[] = $qliroOrderItem;
            }
        }

        return $orderItems;
    }

    /**
     * The rate Qliro reserved the fee with, and failing that the one its amounts imply
     *
     * The fee is Qliro's own line, taken from the checkout response and kept on the payment, so the
     * rate it came with is the one the reservation holds and the capture has to agree with. An older
     * order stored before the fee carried a rate has none, and there the amounts are all there is.
     *
     * A reserved 0 is a statement and is sent as it stands. Only a fee with no rate on it at all
     * falls back to the amounts, which is why this asks whether the key is there rather than
     * whether the rate is above zero: those two differ exactly on the reservation that says 0
     * while its own amounts imply a rate.
     *
     * @param array $qlirooneFee
     * @return float
     */
    private function getVatRate(array $qlirooneFee): float
    {
        if (array_key_exists('VatRate', $qlirooneFee) && $qlirooneFee['VatRate'] !== null) {
            return round((float)$qlirooneFee['VatRate'], 2);
        }

        return $this->lineVatRate->fromPrices(
            (float)$qlirooneFee['PricePerItemIncVat'],
            (float)$qlirooneFee['PricePerItemExVat']
        );
    }
}
