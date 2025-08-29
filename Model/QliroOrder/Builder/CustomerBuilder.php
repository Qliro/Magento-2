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
     * @var QliroOrderCustomerInterfaceFactory
     */
    private $orderCustomerFactory;

    /**
     * @var CustomerAddressBuilder
     */
    private $customerAddressBuilder;

    /**
     * @var AddressFactory
     */
    private $addressFactory;

    /**
     * @var Quote
     */
    private $quote;

    /**
     * @var Config
     */
    private $qliroConfig;

    /**
     * Inject dependencies
     *
     * @param QliroOrderCustomerInterfaceFactory $orderCustomerFactory
     * @param CustomerAddressBuilder $customerAddressBuilder
     * @param AddressFactory $addressFactory
     */
    public function __construct(
        QliroOrderCustomerInterfaceFactory $orderCustomerFactory,
        CustomerAddressBuilder $customerAddressBuilder,
        AddressFactory $addressFactory,
        Config $qliroConfig,
    ) {
        $this->orderCustomerFactory = $orderCustomerFactory;
        $this->customerAddressBuilder = $customerAddressBuilder;
        $this->addressFactory = $addressFactory;
        $this->qliroConfig = $qliroConfig;
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

        try {
            if ($address = $this->getAddress()) {
                $qliroOrderCustomerAddress = $this->customerAddressBuilder->setAddress($address)->create();
                $qliroOrderCustomer->setAddress($qliroOrderCustomerAddress);
                $qliroOrderCustomer->setLockCustomerAddress(false);
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
            $qliroOrderCustomer->setLockCustomerEmail((bool)$this->customer);
        }

        if ($mobileNumber = $this->getMobileNumber()) {
            $qliroOrderCustomer->setMobileNumber($mobileNumber);
            $qliroOrderCustomer->setLockCustomerMobileNumber(false);
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
        $shippingAddress = $this->quote->getShippingAddress();
        if ($this->qliroConfig->getShowAsPaymentMethod()) {
            return $shippingAddress;
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
