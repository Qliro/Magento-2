<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Order;

use Magento\Customer\Api\CustomerRepositoryInterface as CustomerRepository;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Quote\Model\Quote;
use Magento\Checkout\Model\Type\Onepage;
use Magento\Quote\Api\CartManagementInterface as CartManagement;
use Magento\Sales\Api\OrderRepositoryInterface as OrderRepository;
use Magento\Sales\Model\Order;

/**
 * Magento order placer class
 */
readonly class OrderPlacer
{
    /**
     * Class constructor
     *
     * @param CartManagement       $cartManagement
     * @param OrderRepository      $orderRepository
     * @param CustomerRepository   $customerRepository
     */
    public function __construct(
        private CartManagement     $cartManagement,
        private OrderRepository    $orderRepository,
        private CustomerRepository $customerRepository
    ) {
    }

    /**
     * Place a Magento order from the given quote.
     *
     * Uses CartManagementInterface::placeOrder() directly for both guest and logged-in
     * customers. The old guest path via GuestPaymentInformationManagement required a
     * quote_id_mask record and a real guest session context — neither of which is
     * guaranteed when placing a pending order server-side during HtmlSnippet::get().
     * CartManagementInterface::placeOrder($quoteId) works unconditionally for both cases.
     *
     * @param Quote $quote
     * @return Order
     * @throws CouldNotSaveException
     */
    public function place(Quote $quote): Order
    {
        switch ($this->getCheckoutMethod($quote)) {
            case Onepage::METHOD_GUEST:
                $this->prepareGuestQuote($quote);
                break;
            default:
                $this->prepareCustomerQuote($quote);
                break;
        }

        $quote->save();
        $orderId = $this->cartManagement->placeOrder($quote->getId());

        /** @var Order $order */
        $order = $this->orderRepository->get($orderId);

        return $order;
    }

    /**
     * Get quote checkout method
     *
     * @param Quote $quote
     * @return string
     */
    private function getCheckoutMethod(Quote $quote): string
    {
        if ($quote->getCustomerId()) {
            $quote->setCheckoutMethod(Onepage::METHOD_CUSTOMER);
            return $quote->getCheckoutMethod();
        }

        if (!$quote->getCheckoutMethod()) {
            $quote->setCheckoutMethod(Onepage::METHOD_GUEST);
        }

        return $quote->getCheckoutMethod();
    }

    /**
     * Prepare quote for guest checkout order submit
     *
     * @param Quote $quote
     * @return void
     */
    private function prepareGuestQuote(Quote $quote): void
    {
        $quote->setCustomerId(0)
            ->setCustomerEmail($quote->getBillingAddress()->getEmail())
            ->setCustomerIsGuest(true)
            ->setCustomerGroupId(GroupInterface::NOT_LOGGED_IN_ID);
    }

    /**
     * Prepare quote for customer order submit
     *
     * @param Quote $quote
     * @return void
     */
    private function prepareCustomerQuote(Quote $quote): void
    {
        $billing  = $quote->getBillingAddress();
        $shipping = $quote->isVirtual() ? null : $quote->getShippingAddress();

        $customer           = $this->customerRepository->getById($quote->getCustomerId());
        $hasDefaultBilling  = (bool)$customer->getDefaultBilling();
        $hasDefaultShipping = (bool)$customer->getDefaultShipping();

        if ($shipping
            && !$shipping->getSameAsBilling()
            && (!$shipping->getCustomerId() || $shipping->getSaveInAddressBook())
        ) {
            $shippingAddress = $shipping->exportCustomerAddress();
            if (!$hasDefaultShipping) {
                $shippingAddress->setIsDefaultShipping(true);
                $hasDefaultShipping = true;
            }

            $quote->addCustomerAddress($shippingAddress);
            $shipping->setCustomerAddressData($shippingAddress);
        }

        if (!$billing->getCustomerId() || $billing->getSaveInAddressBook()) {
            $billingAddress = $billing->exportCustomerAddress();
            if (!$hasDefaultBilling) {
                if (!$hasDefaultShipping) {
                    $billingAddress->setIsDefaultShipping(true);
                }
                $billingAddress->setIsDefaultBilling(true);
            }

            $quote->addCustomerAddress($billingAddress);
            $billing->setCustomerAddressData($billingAddress);
        }
    }
}
