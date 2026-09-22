<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\Order;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderAddressRepositoryInterface;
use Qliro\QliroOne\Model\Logger\Manager as LogManager;
use Qliro\QliroOne\Model\QliroOrder\Converter\AddressConverter;

/**
 * Write the organisation number of a company buyer to the order it was placed with
 *
 * It is written here and not on the quote on purpose. `vat_id` on a quote address is the field
 * Magento validates against VIES when automatic customer group assignment is on, and it moves the
 * buyer into the group the store keeps for an invalid VAT id when the answer is no. An
 * organisation number is not a VAT number, so that answer would be no for every company buying
 * through Qliro, and the tax class of that group would decide the order. The order is placed
 * before this runs, so the number reaches the invoice, the shipping label and the admin without
 * any of it touching what the buyer pays.
 */
class OrganizationNumber
{
    /**
     * @var AddressConverter
     */
    private $addressConverter;

    /**
     * @var OrderAddressRepositoryInterface
     */
    private $orderAddressRepository;

    /**
     * @var LogManager
     */
    private $logManager;

    /**
     * Inject dependencies
     *
     * @param AddressConverter $addressConverter
     * @param OrderAddressRepositoryInterface $orderAddressRepository
     * @param LogManager $logManager
     */
    public function __construct(
        AddressConverter $addressConverter,
        OrderAddressRepositoryInterface $orderAddressRepository,
        LogManager $logManager
    ) {
        $this->addressConverter = $addressConverter;
        $this->orderAddressRepository = $orderAddressRepository;
        $this->logManager = $logManager;
    }

    /**
     * Put the number Qliro identified the company by on the addresses of the placed order
     *
     * @param OrderInterface $order
     * @param \Qliro\QliroOne\Api\Data\QliroOrderInterface|\Qliro\QliroOne\Api\Data\AdminOrderInterface $qliroOrder
     * @return void
     */
    public function apply(OrderInterface $order, $qliroOrder)
    {
        $customer = $qliroOrder->getCustomer();

        $addresses = [
            [$order->getBillingAddress(), $qliroOrder->getBillingAddress()],
            [$order->getShippingAddress(), $qliroOrder->getShippingAddress()],
        ];

        foreach ($addresses as [$orderAddress, $qliroAddress]) {
            if ($orderAddress === null) {
                continue;
            }

            $number = $this->addressConverter->organizationNumber($qliroAddress, $customer);

            // A number the store already has on the address is its own, whether it typed it or
            // took it from a customer's address book, and it is not ours to replace
            if ($number === null || trim((string)$orderAddress->getVatId()) !== '') {
                continue;
            }

            $orderAddress->setVatId($number);

            try {
                $this->orderAddressRepository->save($orderAddress);
            } catch (\Exception $exception) {
                // The order is placed and paid for by now, so this is a line on the address and
                // never a reason to fail the placement
                $this->logManager->warning(
                    'The organisation number could not be saved on the order address',
                    [
                        'extra' => [
                            'order_id' => $order->getEntityId(),
                            'address_id' => $orderAddress->getEntityId(),
                            'error' => $exception->getMessage(),
                        ],
                    ]
                );
            }
        }
    }
}
