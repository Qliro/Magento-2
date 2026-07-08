<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model\QliroOrder\Builder;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Address;
use Magento\Customer\Model\Address\AbstractAddress;
use Magento\Customer\Model\AddressFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Model\Config;
use Qliro\QliroOne\Model\Config\Source\PaymentMethodRenderMode;

/**
 * QliroOne Order Customer builder class
 */
class CustomerBuilder
{
    private ?CustomerInterface $customer = null;
    private ?Quote $quote = null;

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
    public function setCustomer(?CustomerInterface $customer): static
    {
        $this->customer = $customer;

        return $this;
    }

    /**
     * Set quote for data extraction
     *
     * @param Quote $quote
     * @return static
     */
    public function setQuote(Quote $quote): static
    {
        $this->quote = $quote;

        return $this;
    }

    /**
     * Create a container
     *
     * @return array
     */
    public function create(): array
    {
        $qliroOrderCustomer = [];

        if (!$this->quote) {
            $this->customer = null;
            $this->quote = null;
            return $qliroOrderCustomer;
        }

        $lockData = $this->shouldLockCustomerData();

        try {
            if ($address = $this->getAddress()) {
                $qliroOrderCustomerAddress = $this->customerAddressBuilder->setAddress($address)->create();
                $qliroOrderCustomer['Address'] = $qliroOrderCustomerAddress;
                $qliroOrderCustomer['LockCustomerAddress'] = $lockData;
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
            // Preserve the existing logged-in lock; additionally lock in embedded-iframe mode.
            $qliroOrderCustomer['LockCustomerEmail'] = $lockData || (bool)$this->customer;
        }

        if ($mobileNumber = $this->getMobileNumber()) {
            $qliroOrderCustomer['MobileNumber'] = $mobileNumber;
            $qliroOrderCustomer['LockCustomerMobileNumber'] = $lockData;
        }

        if ($lockData && !empty($qliroOrderCustomer)) {
            $qliroOrderCustomer['LockCustomerInformation'] = true;
        }

        $this->customer = null;
        $this->quote = null;

        return $qliroOrderCustomer;
    }

    /**
     * Whether pre-supplied customer data should be locked (non-editable) in the Qliro iframe.
     *
     *
     * @return bool
     */
    private function shouldLockCustomerData(): bool
    {
        $storeId = $this->quote->getStoreId();

        return $this->qliroConfig->getShowAsPaymentMethod($storeId)
            && $this->qliroConfig->getPaymentMethodRenderMode($storeId) === PaymentMethodRenderMode::MODE_IFRAME;
    }

    /**
     * @return AbstractAddress|null
     */
    protected function getAddress(): ?AbstractAddress
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
    protected function getEmail(): ?string
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
    protected function getMobileNumber(): ?string
    {
        if ($this->quote->getShippingAddress()) {
            return $this->quote->getShippingAddress()->getTelephone();
        }

        return null;
    }
}
