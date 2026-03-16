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
     * Class constructor
     *
     * @param CustomerAddressBuilder $customerAddressBuilder
     * @param AddressFactory $addressFactory
     * @param Config $qliroConfig
     */
    public function __construct(
        private readonly CustomerAddressBuilder $customerAddressBuilder,
        private readonly AddressFactory $addressFactory,
        private readonly Config $qliroConfig
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
     * @return array
     */
    public function create()
    {
        $qliroOrderCustomer = [];

        if (!$this->quote) {
            $this->customer = null;
            $this->quote = null;
            return $qliroOrderCustomer;
        }

        try {
            if ($address = $this->getAddress()) {
                $qliroOrderCustomerAddress = $this->customerAddressBuilder->setAddress($address)->create();
                $qliroOrderCustomer['Address'] = $qliroOrderCustomerAddress;
                $qliroOrderCustomer['LockCustomerAddress'] = false;
                $qliroOrderCustomer['JuridicalType'] = !empty($qliroOrderCustomerAddress['CompanyName'] ?? null)
                    ? 'Company'
                    : 'Physical';
            }
        } catch (LocalizedException $e) {
            $this->customer = null;
            $this->quote = null;
            return $qliroOrderCustomer;
        }

        if ($email = $this->getEmail()) {
            $qliroOrderCustomer['Email'] = $email;
            $qliroOrderCustomer['LockCustomerEmail'] = (bool)$this->customer;
        }

        if ($mobileNumber = $this->getMobileNumber()) {
            $qliroOrderCustomer['MobileNumber'] = $mobileNumber;
            $qliroOrderCustomer['LockCustomerMobileNumber'] = false;
        }

        $this->customer = null;
        $this->quote = null;

        return $qliroOrderCustomer;
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
