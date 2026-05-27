<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Converter;

use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\Data\QliroOrderItemInterface;
use Qliro\QliroOne\Model\Product\Type\QuoteSourceProvider;
use Qliro\QliroOne\Model\Product\Type\TypePoolHandler;
use Qliro\QliroOne\Model\Fee;
use Qliro\QliroOne\Model\QliroOrder\Admin\Builder\Handler\ShippingFeeHandler;

/**
 * QliroOne Order Items Converter class
 */
readonly class OrderItemsConverter
{
    /**
     * Class constructor
     *
     * @param TypePoolHandler $typePoolHandler
     * @param Fee $fee
     * @param QuoteSourceProvider $quoteSourceProvider
     */
    public function __construct(
        private TypePoolHandler     $typePoolHandler,
        private Fee                 $fee,
        private QuoteSourceProvider $quoteSourceProvider,
    ) {
    }

    /**
     * Convert QliroOne order items into relevant quote items
     *
     * @param array $qliroOrderItems
     * @param Quote $quote
     * @throws LocalizedException
     */
    public function convert(array $qliroOrderItems, Quote $quote): void
    {
        $feeAmount = 0;
        $shippingCode = null;
        $this->quoteSourceProvider->setQuote($quote);

        if (!$quote->isVirtual()) {
            $shippingCode = $quote->getShippingAddress()->getShippingMethod();
        }

        $shippingMerchantRef = '';
        foreach ($qliroOrderItems as $index => $orderItem) {
            switch ($orderItem['Type'] ?? null) {
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
     * @param Quote $quote
     * @throws LocalizedException
     */
    private function applyShippingMethod(string $code, Quote $quote, string $shippingMerchantRef = ''): void
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
