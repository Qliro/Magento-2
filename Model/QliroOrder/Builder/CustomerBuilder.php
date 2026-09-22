<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\AddressFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerInterface;
use Qliro\QliroOne\Api\Data\QliroOrderCustomerInterfaceFactory;
use Qliro\QliroOne\Model\Config;

/**
 * QliroOne Order Customer builder class
 */
class CustomerBuilder
{
    /**
     * @var CustomerInterface
     */
    private $customer;

    /**
     * @var Quote
     */
    private $quote;

    /**
     * Inject dependencies
     *
     * @param QliroOrderCustomerInterfaceFactory $orderCustomerFactory
     * @param CustomerAddressBuilder $customerAddressBuilder
     * @param AddressFactory $addressFactory
     */
    public function __construct(
        private QliroOrderCustomerInterfaceFactory $orderCustomerFactory,
        private CustomerAddressBuilder $customerAddressBuilder,
        private AddressFactory $addressFactory,
        private Config $qliroConfig
    ) {
    }

    /**
     * Set a customer to extract data
     *
     * @param CustomerInterface|null $customer
     * @return $this
     */
    public function setCustomer(?CustomerInterface $customer)
    {
        $this->customer = $customer;

        return $this;
    }

    /**
     * Set quote for data extraction
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return $this
     */
    public function setQuote(Quote $quote)
    {
        $this->quote = $quote;

        return $this;
    }

    /**
     * Create a container
     *
     * @return QliroOrderCustomerInterface
     */
    public function create()
    {
        $qliroOrderCustomer = $this->orderCustomerFactory->create();

        if (!$this->quote) {
            $this->customer = null;
            $this->quote = null;
            return $qliroOrderCustomer;
        }

        // The native checkout already collected these in the iframe mode, so the buyer does not
        // retype them in the iframe and cannot move the order to another address behind our back.
        $lockData = $this->qliroConfig->isEmbeddedIframeMode($this->quote->getStoreId());
        $addressIsComplete = false;

        try {
            if ($address = $this->getAddress()) {
                $qliroOrderCustomerAddress = $this->customerAddressBuilder->setAddress($address)->create();
                $qliroOrderCustomer->setAddress($qliroOrderCustomerAddress);
                // Only an address the buyer can actually be held to. A virtual cart takes the
                // billing address, which the native checkout collects inside the payment step,
                // so at the moment Qliro is picked it can still be empty, and locking an empty
                // address leaves the buyer with no field to type one into anywhere.
                $addressIsComplete = $this->isAddressComplete($qliroOrderCustomerAddress);
                $qliroOrderCustomer->setLockCustomerAddress($lockData && $addressIsComplete);
                $qliroOrderCustomer->setJuridicalType(
                    $qliroOrderCustomerAddress->getCompanyName() ? QliroOrderCustomerInterface::JURIDICAL_TYPE_COMPANY
                        : QliroOrderCustomerInterface::JURIDICAL_TYPE_PHYSICAL
                );
            }
        } catch (LocalizedException $e) {
            $this->customer = null;
            $this->quote = null;
            return $qliroOrderCustomer;
        }

        if ($email = $this->getEmail()) {
            $qliroOrderCustomer->setEmail($email);
            // A logged in buyer's email was already locked before the iframe mode existed
            $qliroOrderCustomer->setLockCustomerEmail($lockData || (bool)$this->customer);
        }

        if ($mobileNumber = $this->getMobileNumber()) {
            $qliroOrderCustomer->setMobileNumber($mobileNumber);
            $qliroOrderCustomer->setLockCustomerMobileNumber(false);
        }

        /*
         * The lock Qliro actually reads, and it sits on this block rather than at the top level of
         * the create request. Measured against the sandbox: with it the widget shows the customer
         * as stated and offers neither "Ändra" nor the personal number lookup, without it both are
         * there, and the same flag sent at the top level changes nothing at all.
         *
         * It holds the whole block, the mobile number with it: a `LockCustomerMobileNumber: false`
         * next to it does not reopen that field. The per field flags above are what a store gets
         * when this one cannot be set, and they leave the buyer the "Ändra" button.
         *
         * Only with an address the buyer can be held to. A virtual cart takes the billing address,
         * which the native checkout collects inside the payment step, so at the moment Qliro is
         * picked it can still be empty, and a locked empty address leaves the buyer with no field
         * to type one into anywhere.
         */
        if ($lockData && $addressIsComplete) {
            $qliroOrderCustomer->setLockCustomerInformation(true);
        }

        $this->customer = null;
        $this->quote = null;

        return $qliroOrderCustomer;
    }

    /**
     * Whether the address carries the parts an order can be delivered and invoiced against
     *
     * @param \Qliro\QliroOne\Api\Data\QliroOrderCustomerAddressInterface $address
     * @return bool
     */
    private function isAddressComplete($address)
    {
        foreach (['getStreet', 'getPostalCode', 'getCity'] as $getter) {
            if (trim((string)$address->$getter()) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return \Magento\Customer\Model\Address|Quote\Address|null
     */
    protected function getAddress()
    {
        if ($this->qliroConfig->getShowAsPaymentMethod()) {
            if ($this->quote->getIsVirtual()) {
                return $this->quote->getBillingAddress();
            } else {
                return $this->quote->getShippingAddress();
            }
        }

        if (is_object($this->customer) && $this->customer->getDefaultBilling()) {
            return $this->addressFactory->create()->load($this->customer->getDefaultBilling());
        }

        return null;
    }

    /**
     * @return string|null
     */
    protected function getEmail()
    {
        if ($this->customer && $this->customer->getEmail()) {
            return $this->customer->getEmail();
        }

        if ($this->quote->getShippingAddress() && $this->quote->getShippingAddress()->getEmail()) {
            return $this->quote->getShippingAddress()->getEmail();
        }

        if ($this->quote->getBillingAddress() && $this->quote->getBillingAddress()->getEmail()) {
            return $this->quote->getBillingAddress()->getEmail();
        }

        return null;
    }

    /**
     * @return string|null
     */
    protected function getMobileNumber()
    {
        if ($this->quote->getShippingAddress()) {
            return $this->quote->getShippingAddress()->getTelephone();
        }

        return null;
    }
}
