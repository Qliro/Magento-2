<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Order;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Model\Customer;
use Magento\Quote\Model\Quote;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Checkout\Model\Type\Onepage;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Sales\Model\Order;

/**
 * Magento order placer class
 */
class OrderPlacer
{
    /**
     * Class constructor
     *
     * @param \Magento\Quote\Api\CartRepositoryInterface $quoteRepository
     * @param \Magento\Quote\Api\CartManagementInterface $cartManagement
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository
     */
    public function __construct(
        private readonly CartRepositoryInterface $quoteRepository,
        private readonly CartManagementInterface $cartManagement,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ManagerInterface $eventManager,
        private readonly CustomerRepositoryInterface $customerRepository
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
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    public function place($quote)
    {
        switch ($this->getCheckoutMethod($quote)) {
            case Onepage::METHOD_GUEST:
                $this->prepareGuestQuote($quote);
                break;
            default:
                $this->prepareCustomerQuote($quote);
                break;
        }

        $quote->save(); // quoteRepository->save does stupid things...
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
     * @return $this
     */
    private function prepareGuestQuote(Quote $quote): static
    {
        $quote->setCustomerId(null)
            ->setCustomerEmail($quote->getBillingAddress()->getEmail())
            ->setCustomerIsGuest(true)
            ->setCustomerGroupId(GroupInterface::NOT_LOGGED_IN_ID);
        return $this;
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

        /** @var Customer $customer */
        $customer           = $this->customerRepository->getById($quote->getCustomerId());
        $hasDefaultBilling  = (bool)$customer->getPrimaryBillingAddress();
        $hasDefaultShipping = (bool)$customer->getPrimaryShippingAddress();

        if ($shipping && !$shipping->getSameAsBilling() && (!$shipping->getCustomerId() || $shipping->getSaveInAddressBook())) {
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
