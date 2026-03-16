<?php declare(strict_types=1);

namespace Qliro\QliroOne\Model\MerchantPayment\Builder;

use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\Data\MerchantPaymentCustomerInterface;
use Qliro\QliroOne\Api\Data\MerchantPaymentCustomerInterfaceFactory;

/**
 * Builder for Merchant Payment Customer data container
 */
class CustomerBuilder
{
    private ?Quote $quote = null;

    /**
     * Class constructor
     *
     * @param MerchantPaymentCustomerInterfaceFactory $customerFactory
     */
    public function __construct(
        private readonly MerchantPaymentCustomerInterfaceFactory $customerFactory
    ) {
    }

    /**
     * @param Quote $quote
     * @return void
     */
    public function setQuote(Quote $quote) {
        $this->quote = $quote;
    }

    /**
     * @return MerchantPaymentCustomerInterface
     */
    public function create(): MerchantPaymentCustomerInterface
    {
        if (empty($this->quote)) {
            throw new \LogicException('Quote Entity is not set');
        }

        $customer = $this->customerFactory->create();
        $mainAddress = $this->quote->getBillingAddress();
        if ($this->quote->getIsVirtual()) {
            $mainAddress = $this->quote->getShippingAddress();
        }

        $customer->setEmail($this->quote->getCustomerEmail());
        $customer->setMobileNumber($mainAddress->getTelephone());
        if ($mainAddress->getCompany()) {
            $customer->setJuridicalType(MerchantPaymentCustomerInterface::JURIDICAL_TYPE_COMPANY);
        }

        if ($this->quote->getCustomerPersonalNumber()) {
            $customer->setPersonalNumber($this->quote->getCustomerPersonalNumber());
        }

        // TODO VAT number must be fetched from the original Qliro order
        return $customer;
    }
}
