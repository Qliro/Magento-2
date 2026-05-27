<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\Order;

use Magento\Sales\Api\OrderRepositoryInterface as OrderRepository;
use Magento\Sales\Model\Order;

/**
 * Updates billing and shipping addresses on a placed Magento order from Qliro callback data.
 *
 * Extracted from PlaceOrder::updateOrderAddresses() (SRP).
 */
readonly class OrderAddressUpdater
{
    /**
     * Class constructor
     *
     * @param OrderRepository   $orderRepository
     */
    public function __construct(
        private OrderRepository $orderRepository
    ) {
    }

    /**
     * Overwrite order addresses with confirmed data from the Qliro CheckoutStatus callback.
     *
     * Magento stores order addresses in sales_order_address and exposes them as
     * \Magento\Sales\Model\Order\Address objects. We update in place and save via the
     * OrderRepository so all standard observers and ERP integrations see a consistent order.
     *
     * @param Order $order
     * @param array $qliroOrder
     */
    public function update(Order $order, array $qliroOrder): void
    {
        $qliroCustomer = $qliroOrder['Customer'] ?? [];
        $qliroBilling  = $qliroOrder['BillingAddress'] ?? ($qliroCustomer['Address'] ?? []);
        $qliroShipping = $qliroOrder['ShippingAddress'] ?? $qliroBilling;

        if (!$qliroCustomer && !$qliroBilling) {
            return;
        }

        $addressFields = [
            'firstname'  => $qliroBilling ['FirstName']    ?? null,
            'lastname'   => $qliroBilling ['LastName']     ?? null,
            'email'      => $qliroCustomer['Email']        ?? null,
            'street'     => $qliroBilling ['Street']       ?? null,
            'city'       => $qliroBilling ['City']         ?? null,
            'postcode'   => $qliroBilling ['PostalCode']   ?? null,
            'telephone'  => $qliroCustomer['MobileNumber'] ?? null,
            'company'    => $qliroBilling ['CompanyName']  ?? null,
        ];

        foreach ([$order->getBillingAddress(), $order->getShippingAddress()] as $orderAddress) {
            if (!$orderAddress) {
                continue;
            }

            $source = ($orderAddress->getAddressType() === 'shipping' && $qliroShipping)
                ? $qliroShipping
                : $qliroBilling;

            $fields = $addressFields;
            if ($source && $orderAddress->getAddressType() === 'shipping') {
                $fields['firstname'] = $source['FirstName']   ?? $fields['firstname'];
                $fields['lastname']  = $source['LastName']    ?? $fields['lastname'];
                $fields['street']    = $source['Street']      ?? $fields['street'];
                $fields['city']      = $source['City']        ?? $fields['city'];
                $fields['postcode']  = $source['PostalCode']  ?? $fields['postcode'];
                $fields['company']   = $source['CompanyName'] ?? $fields['company'];
            }

            foreach ($fields as $key => $value) {
                if ($value !== null) {
                    $orderAddress->setData($key, $value);
                }
            }

            if (!empty($qliroOrder['Country']) && !$orderAddress->getCountryId()) {
                $orderAddress->setCountryId($qliroOrder['Country']);
            }
        }

        // Sync email and name on the order itself for guests
        if (!$order->getCustomerId()) {
            if (!empty($qliroCustomer['Email'])) {
                $order->setCustomerEmail($qliroCustomer['Email']);
            }
            if (!empty($qliroBilling['FirstName'])) {
                $order->setCustomerFirstname($qliroBilling['FirstName']);
            }
            if (!empty($qliroBilling['LastName'])) {
                $order->setCustomerLastname($qliroBilling['LastName']);
            }
        }

        $this->orderRepository->save($order);
    }
}
