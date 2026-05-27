<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace Qliro\QliroOne\Model;

use Magento\Checkout\Model\Session;
use Magento\Customer\Api\Data\AddressInterfaceFactory;
use Magento\Customer\Api\Data\RegionInterfaceFactory;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Tax\Api\Data\QuoteDetailsInterfaceFactory;
use Magento\Tax\Api\Data\QuoteDetailsItemInterfaceFactory;
use Magento\Tax\Api\Data\TaxClassKeyInterfaceFactory;
use Magento\Tax\Api\TaxCalculationInterface;
use Magento\Tax\Api\Data\TaxClassKeyInterface;
use Magento\Framework\DataObjectFactory;
use Magento\Quote\Model\Quote;

class Fee
{
    /**
     * @var array
     */
    private array $methodsWithFee = [];

    /**
     * Class constructor
     *
     * @param Config $config
     * @param Session $checkoutSession
     * @param PriceCurrencyInterface $priceCurrency
     * @param CatalogHelper $catalogHelper
     * @param StoreManagerInterface $storeManager
     * @param CustomerSession $customerSession
     * @param TaxClassKeyInterfaceFactory $taxClassKeyFactory
     * @param QuoteDetailsInterfaceFactory $quoteDetailsFactory
     * @param QuoteDetailsItemInterfaceFactory $quoteDetailsItemFactory
     * @param TaxCalculationInterface $taxCalculation
     * @param AddressInterfaceFactory $addressFactory
     * @param RegionInterfaceFactory $regionFactory
     * @param GroupRepositoryInterface $customerGroupRepository
     * @param DataObjectFactory $dataObjectFactory
     */
    public function __construct(
        private readonly Config $config,
        private readonly Session $checkoutSession,
        private readonly PriceCurrencyInterface $priceCurrency,
        private readonly CatalogHelper $catalogHelper,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerSession $customerSession,
        private readonly TaxClassKeyInterfaceFactory $taxClassKeyFactory,
        private readonly QuoteDetailsInterfaceFactory $quoteDetailsFactory,
        private readonly QuoteDetailsItemInterfaceFactory $quoteDetailsItemFactory,
        private readonly TaxCalculationInterface $taxCalculation,
        private readonly AddressInterfaceFactory $addressFactory,
        private readonly RegionInterfaceFactory $regionFactory,
        private readonly GroupRepositoryInterface $customerGroupRepository,
        private readonly DataObjectFactory $dataObjectFactory
    ) {
    }

    /**
     * Sets the fee including Tax, quote should be recalculated after this, to update all remaining fields
     *
     * @param Quote $quote
     * @param float $fee
     * @return void
     */
    public function setQlirooneFeeInclTax(Quote $quote, float $fee): void
    {
        if ($quote->isVirtual()) {
            $quote->getBillingAddress()->setQlirooneFee($fee);
        } else {
            $quote->getShippingAddress()->setQlirooneFee($fee);
        }
    }

    /**
     * Returns the amount of the fee, if defined. It can be fixed or a percent of the order sum
     * This function must not depend on display settings
     *
     * @param Quote $quote
     * @return float
     */
    public function getQlirooneFeeInclTax(Quote $quote): float
    {
        if ($quote->isVirtual()) {
            $fee = $quote->getBillingAddress()->getQlirooneFee();
        } else {
            $fee = $quote->getShippingAddress()->getQlirooneFee();
        }

        return (float)$this->getCalcTaxPrice($quote, $fee, true);
    }

    /**
     * Return Fee excluding tax
     *
     * @param Quote $quote
     * @return float
     */
    public function getQlirooneFeeExclTax(Quote $quote): float
    {
        $price = $this->getQlirooneFeeInclTax($quote);

        return (float)$this->getCalcTaxPrice($quote, $price, false);
    }

    /**
     * @todo Improvement. Proper currency conversion to handle display currencies
     *
     * @param Quote $quote
     * @return float
     */
    public function getBaseQlirooneFeeInclTax(Quote $quote): float
    {
        return $this->getQlirooneFeeInclTax($quote);
    }

    /**
     * @todo Improvement. Proper currency conversion to handle display currencies
     *
     * @param Quote $quote
     * @return float
     */
    public function getBaseQlirooneFeeExclTax(Quote $quote): float
    {
        return $this->getQlirooneFeeExclTax($quote);
    }

    /**
     * Get the summary for cart and checkout
     *
     * @param Quote $quote
     * @param float $amount
     * @return array
     */
    public function getFeeArray(Quote $quote, float $amount): array
    {
        $feeSetup = $this->getFeeSetup($quote->getStoreId());
        if (!$amount || empty($feeSetup)) {
            return [];
        }
        $result = [
            'code' => Config::TOTALS_FEE_CODE,
            'title' => __($feeSetup[Config::CONFIG_FEE_TITLE]),
            'value' => $amount,
        ];
        return $result;
    }

    /**
     * Get the object, used for Totals in both FE and BE on orders, creditnotes and invoices
     *
     * @param mixed $storeId
     * @param float $amount
     * @return \Magento\Framework\DataObject
     */
    public function getFeeObject(mixed $storeId, float $amount): \Magento\Framework\DataObject
    {
        $feeSetup = $this->getFeeSetup($storeId);
        $feeObject = $this->dataObjectFactory->create();
        if (!$amount || empty($feeSetup)) {
            return $feeObject;
        }
        if ($feeSetup) {
            $title = __($feeSetup[Config::CONFIG_FEE_TITLE]);
        } else {
            $title = __('Payment fee');
        }
        $feeObject->setData([
            'code' => Config::TOTALS_FEE_CODE,
            'strong' => false,
            'value' => $amount,
            'label' => $title,
        ]);
        return $feeObject;
    }

    /**
     * Convert a fee array to a fee object
     *
     * @param array $qlirooneFee
     * @return \Magento\Framework\DataObject
     */
    public function feeToFeeObject(array $qlirooneFee): \Magento\Framework\DataObject
    {
        $feeObject = $this->dataObjectFactory->create();
        $feeObject->setData([
            'code' => $qlirooneFee['MerchantReference'],
            'strong' => false,
            'value' => $qlirooneFee['PricePerItemIncVat'],
            'label' => $qlirooneFee['Description'],
        ]);
        return $feeObject;
    }

    /**
     * Will return fee setup, including an amount of zero
     *
     * @param mixed $storeId
     * @return array
     */
    public function getFeeSetup(mixed $storeId): array
    {
        if (!$this->config->isActive($storeId)) {
            return [];
        }
        if (!$this->methodsWithFee) {
            $title = $this->config->getFeeMerchantReference();
            $this->methodsWithFee = array(
                Config::CONFIG_FEE_AMOUNT => 0,
                Config::CONFIG_FEE_TITLE => $title,
            );
        }
        return $this->methodsWithFee;
    }

    /**
     * Picks up the amounts from Fees and runs them through the getTaxPrice function,
     * which changes things depending on display settings etc
     *
     * @param Quote $quote
     * @param array $feeCalc
     * @return array
     */
    public function applyDisplayFlagsToFeeArray(Quote $quote, array $feeCalc): array
    {
        if (empty($feeCalc)) {
            return [];
        }
        if ($feeCalc[Config::CONFIG_FEE_AMOUNT]) {
            $price = $feeCalc[Config::CONFIG_FEE_AMOUNT];
            $feeCalc[Config::CONFIG_FEE_AMOUNT] = $this->getTaxPrice($quote, $price);
        }

        return $feeCalc;
    }

    /**
     * Get current quote from checkout session
     *
     * @return Quote
     */
    public function getQuote(): Quote
    {
        return $this->checkoutSession->getQuote();
    }

    /**
     * Get merchant reference for the Qliro Invoice Fee
     *
     * @return string
     */
    public function getMerchantReference(): string
    {
        return (string)$this->config->getFeeMerchantReference();
    }

    /**
     * Returns the price including or excluding tax, depending on flags being sent in and display settings
     *
     * @param Quote $quote
     * @param float $price
     * @param bool|null $includingTax
     * @param bool|null $feeIncludesTax
     * @return float
     */
    private function getTaxPrice(Quote $quote, float $price, ?bool $includingTax = null, ?bool $feeIncludesTax = null): float
    {
        $pseudoProduct = new \Magento\Framework\DataObject();
        $pseudoProduct->setTaxClassId(
            $this->config->getFeeTaxClass($quote->getStoreId())
        );

        $shippingAddress = null;
        $billingAddress = null;
        $ctc = null;

        if ($feeIncludesTax === null) {
            $feeIncludesTax = $this->config->paymentFeeIncludesTax($quote->getStoreId());
        }

        return (float)$this->catalogHelper->getTaxPrice(
            $pseudoProduct,
            $price,
            $includingTax,
            $shippingAddress,
            $billingAddress,
            $ctc,
            $quote->getStoreId(),
            $feeIncludesTax
        );
    }

    /**
     * Returns the price including or excluding tax, NOT depending on display settings
     * Basically a copy of above used function $this->catalogHelper->getTaxPrice
     *
     * @param Quote $quote
     * @param float $price
     * @param bool $includingTax
     * @param bool|null $feeIncludesTax
     * @return float
     */
    private function getCalcTaxPrice(Quote $quote, float $price, bool $includingTax, ?bool $feeIncludesTax = null): float
    {
        if (!$price) {
            return $price;
        }

        $product = new \Magento\Framework\DataObject();
        $product->setTaxClassId(
            $this->config->getFeeTaxClass($quote->getStoreId())
        );

        $shippingAddress = null;
        $billingAddress = null;
        $ctc = null;
        $roundPrice = true;

        $store = $this->storeManager->getStore($quote->getStoreId());
        if ($feeIncludesTax === null) {
            $feeIncludesTax = $this->config->paymentFeeIncludesTax($quote->getStoreId());
        }

        $shippingAddressDataObject = null;
        if ($shippingAddress === null) {
            $shippingAddressDataObject =
                $this->convertDefaultTaxAddress($this->customerSession->getDefaultTaxShippingAddress());
        } elseif ($shippingAddress instanceof \Magento\Customer\Model\Address\AbstractAddress) {
            $shippingAddressDataObject = $shippingAddress->getDataModel();
        }

        $billingAddressDataObject = null;
        if ($billingAddress === null) {
            $billingAddressDataObject =
                $this->convertDefaultTaxAddress($this->customerSession->getDefaultTaxBillingAddress());
        } elseif ($billingAddress instanceof \Magento\Customer\Model\Address\AbstractAddress) {
            $billingAddressDataObject = $billingAddress->getDataModel();
        }

        $taxClassKey = $this->taxClassKeyFactory->create();
        $taxClassKey->setType(TaxClassKeyInterface::TYPE_ID)
            ->setValue($product->getTaxClassId());

        if ($ctc === null && $this->customerSession->getCustomerGroupId() != null) {
            $ctc = $this->customerGroupRepository->getById($this->customerSession->getCustomerGroupId())
                ->getTaxClassId();
        }

        $customerTaxClassKey = $this->taxClassKeyFactory->create();
        $customerTaxClassKey->setType(TaxClassKeyInterface::TYPE_ID)
            ->setValue($ctc);

        $item = $this->quoteDetailsItemFactory->create();
        $item->setQuantity(1)
            ->setCode($product->getSku())
            ->setShortDescription($product->getShortDescription())
            ->setTaxClassKey($taxClassKey)
            ->setIsTaxIncluded($feeIncludesTax)
            ->setType('product')
            ->setUnitPrice($price);

        $quoteDetails = $this->quoteDetailsFactory->create();
        $quoteDetails->setShippingAddress($shippingAddressDataObject)
            ->setBillingAddress($billingAddressDataObject)
            ->setCustomerTaxClassKey($customerTaxClassKey)
            ->setItems([$item])
            ->setCustomerId($this->customerSession->getCustomerId());

        $storeId = null;
        if ($store) {
            $storeId = $store->getId();
        }
        $taxDetails = $this->taxCalculation->calculateTax($quoteDetails, $storeId, $roundPrice);
        $items = $taxDetails->getItems();
        $taxDetailsItem = array_shift($items);

        if ($includingTax) {
            $price = $taxDetailsItem->getPriceInclTax();
        } else {
            $price = $taxDetailsItem->getPrice();
        }

        if ($roundPrice) {
            return (float)$this->priceCurrency->round($price);
        } else {
            return (float)$price;
        }
    }

    /**
     * @param array|null $taxAddress
     * @return \Magento\Customer\Api\Data\AddressInterface|null
     */
    private function convertDefaultTaxAddress(?array $taxAddress = null): ?\Magento\Customer\Api\Data\AddressInterface
    {
        if (empty($taxAddress)) {
            return null;
        }
        /** @var \Magento\Customer\Api\Data\AddressInterface $addressDataObject */
        $addressDataObject = $this->addressFactory->create()
            ->setCountryId($taxAddress['country_id'])
            ->setPostcode($taxAddress['postcode']);

        if (isset($taxAddress['region_id'])) {
            $addressDataObject->setRegion($this->regionFactory->create()->setRegionId($taxAddress['region_id']));
        }
        return $addressDataObject;
    }
}
