<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Converter;

use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\SubscriptionInterface;

/**
 * Quote from QliroOne order container converter class
 */
readonly class QuoteFromOrderConverter
{
    /**
     * Class constructor
     *
     * @param CustomerConverter $customerConverter
     * @param AddressConverter $addressConverter
     * @param OrderItemsConverter $orderItemsConverter
     * @param SubscriptionInterface $subscription
     */
    public function __construct(
        private CustomerConverter     $customerConverter,
        private AddressConverter      $addressConverter,
        private OrderItemsConverter   $orderItemsConverter,
        private SubscriptionInterface $subscription
    ) {
    }

    /**
     * Convert update shipping methods request into quote
     *
     * @param array $qliroOrder Raw array from MerchantInterface::getOrder()
     * @param \Magento\Quote\Model\Quote $quote
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function convert(array $qliroOrder, Quote $quote): void
    {
        $qliroCustomer = $qliroOrder['Customer'] ?? [];

        $this->customerConverter->convert($qliroCustomer, $quote);

        $billingAddress = $qliroOrder['BillingAddress'] ?? [];
        if ($billingAddress) {
            $this->addressConverter->convert(
                $billingAddress,
                $qliroCustomer,
                $quote->getBillingAddress()
            );
        }

        if (!$quote->isVirtual()) {
            $shippingAddress = $qliroOrder['ShippingAddress'] ?? [];
            if ($shippingAddress) {
                $this->addressConverter->convert(
                    $shippingAddress,
                    $qliroCustomer,
                    $quote->getShippingAddress()
                );
            }
        }

        $this->orderItemsConverter->convert($qliroOrder['OrderItems'] ?? [], $quote);

        if (!empty($qliroOrder['SignupForNewsletter'])) {
            $email = $quote->getCustomer()->getEmail();
            $this->subscription->addSubscription($email, $quote->getStoreId());
        }
    }
}
