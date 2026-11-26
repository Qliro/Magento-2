<?php declare(strict_types=1);

namespace Qliro\QliroOne\Model\MerchantPayment\Builder;

use Magento\Framework\App\ObjectManager;
use Magento\Quote\Model\Quote;
use Qliro\QliroOne\Api\Data\MerchantPaymentCustomerInterface;
use Qliro\QliroOne\Api\Data\MerchantPaymentCustomerInterfaceFactory;
use Qliro\QliroOne\Model\Config;

/**
 * Builder for Merchant Payment Customer data container
 */
class CustomerBuilder
{
    private MerchantPaymentCustomerInterfaceFactory $customerFactory;

    private Config $qliroConfig;

    private ?Quote $quote = null;

    public function __construct(
        MerchantPaymentCustomerInterfaceFactory $customerFactory,
        ?Config $qliroConfig = null
    ) {
        $this->customerFactory = $customerFactory;
        // Magento passes null for an optional argument, so it is resolved here
        $this->qliroConfig = $qliroConfig ?: ObjectManager::getInstance()->get(Config::class);
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
        $privateOnly = $this->qliroConfig->isPrivateCustomersOnly($this->quote->getStoreId());
        if ($mainAddress->getCompany() && !$privateOnly) {
            $customer->setJuridicalType(MerchantPaymentCustomerInterface::JURIDICAL_TYPE_COMPANY);
        }

        if ($this->quote->getCustomerPersonalNumber()) {
            $customer->setPersonalNumber($this->quote->getCustomerPersonalNumber());
        }

        // TODO VAT number must be fetched from the original Qliro order
        return $customer;
    }
}
