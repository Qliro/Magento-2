<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Converter;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Model\Product\Type\QuoteSourceProvider;
use Qliro\QliroOne\Model\Product\Type\TypePoolHandler;
use Qliro\QliroOne\Model\Payload\PayloadConverter;
use Qliro\QliroOne\Model\Fee;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\InvoiceFeeHandler;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\ShippingFeeHandler;

/**
 * QliroOne Order Items Converter class
 */
class OrderItemsConverter
{
    /**
     * Class constructor
     *
     * @param TypePoolHandler $typePoolHandler
     * @param Fee $fee
     * @param QuoteSourceProvider $quoteSourceProvider
     * @param PayloadConverter $payloadConverter
     */
    public function __construct(
        private readonly TypePoolHandler $typePoolHandler,
        private readonly Fee $fee,
        private readonly QuoteSourceProvider $quoteSourceProvider,
        private readonly PayloadConverter $payloadConverter
    ) {
    }

    /**
     * Convert QliroOne order items into relevant quote items
     *
     * @param array $qliroOrderItems
     * @param \Magento\Quote\Model\Quote $quote
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function convert($qliroOrderItems, Quote $quote)
    {
        $feeAmount = 0;
        $shippingCode = null;
        $this->quoteSourceProvider->setQuote($quote);

        if (!$quote->isVirtual()) {
            $shippingCode = $quote->getShippingAddress()->getShippingMethod();
        }

        $shippingMerchantRef = '';
        foreach ($qliroOrderItems as $index => $orderItem) {
            switch (isset($orderItem['Type']) && $orderItem['Type']) {
                case QliroOrderItemInterface::TYPE_PRODUCT:
                    $this->typePoolHandler->resolveQuoteItem($orderItem, $this->quoteSourceProvider);
                    break;

                case QliroOrderItemInterface::TYPE_SHIPPING:
                    $shippingMerchantRef = $orderItem['MerchantReference'] ?? '';
                    break;

                case QliroOrderItemInterface::TYPE_DISCOUNT:
                    // Not doing it now
                    break;

                case QliroOrderItemInterface::TYPE_FEE:
                    $qliroFee = $this->payloadConverter->toArray($orderItem);
                    $quote->getPayment()->setAdditionalInformation(
                        "qliroone_fees",
                        [$index => $orderItem]
                    );
                    break;
            }
        }

        if (!$quote->isVirtual() && $shippingCode && $shippingMerchantRef) {
            $this->applyShippingMethod($shippingCode, $quote, $shippingMerchantRef);
        }

        //$this->fee->setQlirooneFeeInclTax($quote, $feeAmount);
    }

    /**
     * @param string $code
     * @param \Magento\Quote\Model\Quote $quote
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    private function applyShippingMethod($code, Quote $quote, string $shippingMerchantRef = '')
    {
        if (empty($code)) {
            throw new LocalizedException(__('Invalid shipping method, empty code.'));
        }

        $rate = $quote->getShippingAddress()->getShippingRateByCode($code);

        if (!$rate) {
            throw new LocalizedException(__('Invalid shipping method, blank rate.'));
        }

        if ($quote->isMultipleShippingAddresses()) {
            throw new LocalizedException(
                __('There are more than one shipping addresses.')
            );
        }

        $extensionAttributes = $quote->getExtensionAttributes();

        if ($extensionAttributes !== null) {
            $shippingAssignments = $quote->getExtensionAttributes()->getShippingAssignments();

            if(is_array($shippingAssignments)) {
                foreach ($shippingAssignments as $assignment) {
                    $assignment->getShipping()->setMethod($code);
                }
            }
        }

        $quote->getShippingAddress()->setShippingMethod($code);

        if (!!$shippingMerchantRef) {
            $quote->getPayment()->setAdditionalInformation(
                ShippingFeeHandler::MERCHANT_REFERENCE_CODE_FIELD,
                $shippingMerchantRef
            );
        }
    }
}
