<?php
/**
 * Copyright © Qliro AB. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Qliro\QliroOne\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session;
use Magento\Store\Model\StoreManagerInterface;
use Qliro\QliroOne\Model\Security\AjaxToken;
use Qliro\QliroOne\Model\Management\CountrySelect;
use Qliro\QliroOne\Service\RecurringPayments\Data as RecurringPaymentsDataService;

/**
 * QliroOne Checkout config provider class
 */
class CheckoutConfigProvider implements ConfigProviderInterface
{
    /**
     * @var \Magento\Quote\Model\Quote
     */
    private $quote;

    /**
     * Class constructor
     *
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Qliro\QliroOne\Model\Security\AjaxToken $ajaxToken
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Qliro\QliroOne\Model\Config $qliroConfig
     * @param \Qliro\QliroOne\Model\Fee $fee
     * @param CountrySelect $countrySelect
     * @param \Qliro\QliroOne\Service\RecurringPayments\Data $recurringPaymentsDataService
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly AjaxToken $ajaxToken,
        Session $checkoutSession,
        private readonly Config $qliroConfig,
        private readonly \Qliro\QliroOne\Model\Fee $fee,
        private readonly CountrySelect $countrySelect,
        private readonly RecurringPaymentsDataService $recurringPaymentsDataService
    ) {
        $this->quote = $checkoutSession->getQuote();
    }

    /**
     * @return array
     */
    public function getConfig()
    {
        $config = [
            'qliro' => [
                'enabled' => $this->qliroConfig->isActive(),
                'isDebug' => $this->qliroConfig->isDebugMode(),
                'isEagerCheckoutRefresh' => $this->qliroConfig->isEagerCheckoutRefresh(),
                'showAsPaymentMethod' => $this->qliroConfig->getShowAsPaymentMethod(),
                'checkoutTitle' => $this->qliroConfig->getTitle(),
                'securityToken' => $this->ajaxToken->setQuote($this->quote)->getToken(),
                'checkoutUrl' => $this->getUrl('checkout/qliro'),
                'updateQuoteUrl' => $this->getUrl('checkout/qliro_ajax/updateQuote'),
                'updateCustomerUrl' => $this->getUrl('checkout/qliro_ajax/updateCustomer'),
                'updateShippingMethodUrl' => $this->getUrl('checkout/qliro_ajax/updateShippingMethod'),
                'updateShippingPriceUrl' => $this->getUrl('checkout/qliro_ajax/updateShippingPrice'),
                'updatePaymentMethodUrl' => $this->getUrl('checkout/qliro_ajax/updatePaymentMethod'),
                'qliroone_fee' => []
            ],
        ];

        if ($this->qliroConfig->isUseCountrySelector()) {
            $config['qliro']['countrySelector'] = [
               'availableCountries' => $this->qliroConfig->getAvailableCountries(),
               'selectedCountry' => $this->getSelectedCountry()
            ];
        }

        if ($this->qliroConfig->isUseRecurring()) {
            $config['qliro']['recurringOrder'] = [
                'enabled' => true,
                'isRecurring' => $this->getIsRecurring(),
                'availableFrequencyOptions' => $this->recurringPaymentsDataService->formatRecurringFrequencyOptionsJson(
                    $this->qliroConfig->getRecurringFrequencyOptions()
                )
            ];
        }

        return $config;
    }

    private function getUrl($path)
    {
        return $this->storeManager->getStore()->getUrl($path);
    }

    private function getSelectedCountry(): string
    {
        $selectedCountry = $this->countrySelect->getSelectedCountry();
        if (!!$selectedCountry) {
            return $selectedCountry;
        }

        $quote = $this->quote;
        $mainAddress = $quote->getShippingAddress();
        if ($quote->isVirtual()) {
            $mainAddress = $quote->getBillingAddress();
        }

        $addressCountry = $mainAddress->getCountryId();
        if (!$addressCountry) {
            return $this->qliroConfig->getDefaultCountry();
        }
        return $addressCountry;
    }

    private function getIsRecurring(): bool
    {
        $info = $this->recurringPaymentsDataService->quoteGetter($this->quote);
        return !!$info->getEnabled();
    }
}
