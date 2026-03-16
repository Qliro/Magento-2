<?php declare(strict_types=1);

namespace Qliro\QliroOne\Model\Management;

use Qliro\QliroOne\Model\Config;
use Magento\Checkout\Model\Session;

class CountrySelect
{
    /**
     * Class constructor
     *
     * @param Config $config
     * @param Session $checkoutSession
     */
    public function __construct(
        private readonly Config $config,
        private readonly Session $checkoutSession
    ) {
    }

    /**
     * Country selector is enabled in config
     *
     * @return boolean
     */
    public function isEnabled(): bool
    {
        return $this->config->isUseCountrySelector();
    }

    /**
     * Register country selector input, with signifier for if country is changed
     *
     * @param string $countryId
     */
    public function registerCountryChangeInput(string $countryId): void
    {
        if (!$countryId) {
            return;
        }

        $selectedCountry = $this->checkoutSession->getSelectedCountry() ?? '';
        $this->checkoutSession->setSelectedCountry($selectedCountry . '|' . $countryId);
    }

    /**
     * Gets last country change input, not including changes
     *
     * @return string
     */
    public function getSelectedCountry(): string
    {
        $selectedCountryInfo = $this->checkoutSession->getSelectedCountry() ?? '';
        $infoParts = explode('|', $selectedCountryInfo);
        $lastInput = array_pop($infoParts);
        return $lastInput;
    }

    /**
     * If country has changed
     *
     * @return boolean
     */
    public function countryHasChanged(): bool
    {
        $selectedCountry = $this->checkoutSession->getSelectedCountry();
        if (!$selectedCountry) {
            return false;
        }

        $containsChange = strpos($selectedCountry, '|') !== false;
        if (!$containsChange) {
            return false;
        }

        $this->registerChangedCountry();
        return true;
    }

    /**
     * Registers changed country by storing last input without change signifier '|'
     *
     * @return void
     */
    private function registerChangedCountry(): void
    {
        $selectedCountry = $this->checkoutSession->getSelectedCountry();
        $infoParts = explode('|', $selectedCountry);
        $lastInput = array_pop($infoParts);
        $this->checkoutSession->setSelectedCountry($lastInput);
    }
}
