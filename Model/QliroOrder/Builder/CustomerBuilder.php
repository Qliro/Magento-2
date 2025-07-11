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
        /** @var QliroOrderCustomerInterface $qliroOrderCustomer */
        $qliroOrderCustomer = $this->extractVisitorData(
            $this->extractDataFromCustomer($this->orderCustomerFactory->create())
        );

        $this->customer = null;
        $this->quote = null;

        return $qliroOrderCustomer;
    }

    /**
     * Extract data from the given Qliro order customer.
     *
     * @param QliroOrderCustomerInterface $qliroOrderCustomer The provided Qliro order customer instance.
     * @return QliroOrderCustomerInterface The provided Qliro order customer instance.
     */
    protected function extractDataFromCustomer(QliroOrderCustomerInterface $qliroOrderCustomer)
    {
        if (empty($this->customer)) {
            return $qliroOrderCustomer;
        }

        try {
            $addressId = $this->customer->getDefaultBilling();
            $address = $this->addressFactory->create()->load($addressId);
        } catch (LocalizedException $e) {
            return $qliroOrderCustomer;
        }

        $qliroOrderCustomerAddress = $this->customerAddressBuilder->setAddress($address)->create();

        $qliroOrderCustomer->setEmail($this->customer->getEmail());
        $qliroOrderCustomer->setMobileNumber(null);
        $qliroOrderCustomer->setAddress($qliroOrderCustomerAddress);
        $qliroOrderCustomer->setLockCustomerEmail(true);
        $qliroOrderCustomer->setLockCustomerMobileNumber(false);
        $qliroOrderCustomer->setLockCustomerAddress(false);
        $qliroOrderCustomer->setJuridicalType(
            $qliroOrderCustomerAddress->getCompanyName() ? QliroOrderCustomerInterface::JURIDICAL_TYPE_COMPANY
                : QliroOrderCustomerInterface::JURIDICAL_TYPE_PHYSICAL
        );

        return $qliroOrderCustomer;
    }

    /**
     * Extract visitor data from the given quote.
     *
     * @param QliroOrderCustomerInterface $qliroOrderCustomer The provided Qliro order customer instance.
     * @return QliroOrderCustomerInterface The provided Qliro order customer instance.
     */
    protected function extractVisitorData(QliroOrderCustomerInterface $qliroOrderCustomer)
    {
        if (!$this->quote || !$this->qliroConfig->getShowAsPaymentMethod()) {
            return $qliroOrderCustomer;
        }

        $address = $this->quote->getBillingAddress();
        if (!$address) {
            return $qliroOrderCustomer;
        }

        $qliroOrderCustomerAddress = $this->customerAddressBuilder->setAddress($address)->create();

        $qliroOrderCustomer->setEmail($address->getEmail());
        $qliroOrderCustomer->setMobileNumber($address->getTelephone());
        $qliroOrderCustomer->setAddress($qliroOrderCustomerAddress);
        $qliroOrderCustomer->setLockCustomerEmail(false);
        $qliroOrderCustomer->setLockCustomerMobileNumber(false);
        $qliroOrderCustomer->setLockCustomerInformation(false);
        $qliroOrderCustomer->setLockCustomerAddress(false);

        $qliroOrderCustomer->setJuridicalType(
            $qliroOrderCustomerAddress->getCompanyName() ? QliroOrderCustomerInterface::JURIDICAL_TYPE_COMPANY
                : QliroOrderCustomerInterface::JURIDICAL_TYPE_PHYSICAL
        );

        return $qliroOrderCustomer;
    }
}
